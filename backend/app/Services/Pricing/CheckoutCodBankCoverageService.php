<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only bank-credit coverage packet; DOES NOT post COD payment. */
final class CheckoutCodBankCoverageService
{
    public const COMPILE_ABILITY = 'cod.bank-coverage.compile';
    public const REVIEW_ABILITY = 'cod.bank-coverage.review';

    public function __construct(
        private readonly CheckoutCodBankCoverageEvaluator $evaluator
    ) {
    }

    /** @return array<string,mixed> */
    public function compile(string $kind, string $sourceKey, string $operationKey): array
    {
        $actor = $this->actor(self::COMPILE_ABILITY);
        if (!in_array($kind, ['single', 'batch'], true)
            || !Str::isUuid($sourceKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD coverage packet request');
        }
        $sourceKey = strtolower($sourceKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use ($actor, $kind, $sourceKey, $operationKey): array {
            $view = $this->evaluator->inspect($kind, $sourceKey, true);
            if (!$view['can_compile']) {
                throw new DomainException('Bank coverage is not complete or current');
            }
            if (in_array($actor, $view['participants'], true)) {
                throw new DomainException('Independent bank coverage compiler required');
            }
            $hmac = $this->hmac([
                'cod-bank-coverage-compile-v1', $kind, $sourceKey,
                $operationKey, $actor,
            ]);
            $old = DB::table('checkout_cod_bank_coverage_packets')
                ->where('operation_key', $operationKey)->first();
            if ($old !== null) {
                if ($old->source_kind !== $kind
                    || (int) $old->source_id !== $view['source_id']
                    || !hash_equals((string) $old->request_hmac, $hmac)
                    || !hash_equals((string) $old->snapshot_hmac, $view['snapshot_hmac'])) {
                    throw new DomainException('Coverage packet UUID reused or source has changed');
                }
                return $this->present($old, true);
            }
            if (DB::table('checkout_cod_bank_coverage_packets')
                ->where('source_kind', $kind)->where('source_id', $view['source_id'])
                ->where('snapshot_hmac', $view['snapshot_hmac'])->exists()) {
                throw new DomainException('Identical coverage snapshot already compiled');
            }
            $id = DB::table('checkout_cod_bank_coverage_packets')->insertGetId([
                'operation_key' => $operationKey,
                'source_kind' => $kind,
                'source_id' => $view['source_id'],
                'reconciliation_id' => $view['reconciliation_id'],
                'statement_entry_id' => $view['statement_entry_id'],
                'expected_orders_count' => $view['expected_orders_count'],
                'bank_net_minor' => $view['bank_net_minor'],
                'gross_minor' => $view['gross_minor'],
                'fee_minor' => $view['fee_minor'],
                'allocated_minor' => $view['allocated_minor'],
                'snapshot_hmac' => $view['snapshot_hmac'],
                'compiled_by' => $actor,
                'request_hmac' => $hmac,
                'created_at' => now(),
            ]);
            foreach ($view['lines'] as $line) {
                if (!$line['preflight_current'] || $line['preflight_id'] === null) {
                    throw new DomainException('Unreviewed order in coverage packet');
                }
                DB::table('checkout_cod_bank_coverage_lines')->insert([
                    'packet_id' => $id,
                    'order_id' => $line['order_id'],
                    'preflight_id' => $line['preflight_id'],
                    'gross_minor' => $line['gross_minor'],
                    'fee_minor' => $line['fee_minor'],
                    'allocated_minor' => $line['allocated_minor'],
                    'created_at' => now(),
                ]);
            }
            return $this->present(
                DB::table('checkout_cod_bank_coverage_packets')->where('id', $id)->first(),
                false
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(string $packetKey, string $reviewKey, string $decision): array
    {
        $actor = $this->actor(self::REVIEW_ABILITY);
        if (!Str::isUuid($packetKey) || !Str::isUuid($reviewKey)
            || !in_array($decision, ['retain_unverified', 'escalate_investigation'], true)) {
            throw new InvalidArgumentException('Invalid coverage packet review');
        }
        $packetKey = strtolower($packetKey);
        $reviewKey = strtolower($reviewKey);
        $reason = $decision === 'retain_unverified'
            ? 'external_bank_confirmation_required' : 'coverage_exception_detected';

        return DB::transaction(function () use ($actor, $packetKey, $reviewKey, $decision, $reason): array {
            $packet = DB::table('checkout_cod_bank_coverage_packets')
                ->where('operation_key', $packetKey)->lockForUpdate()->first();
            if ($packet === null) {
                throw new DomainException('Bank coverage packet missing');
            }
            $sourceTable = $packet->source_kind === 'single'
                ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';
            $sourceKey = DB::table($sourceTable)
                ->where('id', $packet->source_id)->value('operation_key');
            if ($sourceKey === null) {
                throw new DomainException('Bank coverage source missing');
            }
            $current = $this->evaluator->inspect(
                (string) $packet->source_kind, (string) $sourceKey, true
            );
            if ((int) $packet->compiled_by === $actor
                || in_array($actor, $current['participants'], true)) {
                throw new DomainException('Coverage reviewer must be independent');
            }
            $signature = $this->hmac([
                'cod-bank-coverage-review-v1', $packetKey,
                $reviewKey, $decision, $reason, $actor, (string) $packet->request_hmac,
            ]);
            $old = DB::table('checkout_cod_bank_coverage_reviews')
                ->where('packet_id', $packet->id)->first();
            if ($old !== null) {
                if ($old->review_key !== $reviewKey
                    || !hash_equals((string) $old->request_hmac, $signature)) {
                    throw new DomainException('Coverage packet already reviewed');
                }
                return $this->presentReview($old, true);
            }
            $latestId = (int) DB::table('checkout_cod_bank_coverage_packets')
                ->where('source_kind', $packet->source_kind)
                ->where('source_id', $packet->source_id)->max('id');
            $fresh = (int) $packet->id === $latestId
                && hash_equals((string) $packet->snapshot_hmac, $current['snapshot_hmac']);
            if ($decision === 'retain_unverified' && (!$fresh || !$current['can_compile'])) {
                throw new DomainException('Stale or unsafe COD bank coverage cannot be retained');
            }
            $id = DB::table('checkout_cod_bank_coverage_reviews')->insertGetId([
                'review_key' => $reviewKey,
                'packet_id' => $packet->id,
                'decision' => $decision,
                'reason_code' => $reason,
                'reviewed_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);
            return $this->presentReview(
                DB::table('checkout_cod_bank_coverage_reviews')->where('id', $id)->first(),
                false
            );
        }, 3);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('Bank coverage permission denied');
        }
        return (int) $id;
    }

    private function hmac(array $parts): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), $secret);
    }

    /** @return array<string,mixed> */
    private function present(object $r, bool $replay): array
    {
        return [
            'packet_id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'source_kind' => (string) $r->source_kind,
            'source_id' => (int) $r->source_id,
            'expected_orders_count' => (int) $r->expected_orders_count,
            'gross_minor' => (int) $r->gross_minor,
            'fee_minor' => (int) $r->fee_minor,
            'allocated_minor' => (int) $r->allocated_minor,
            'bank_net_minor' => (int) $r->bank_net_minor,
            'idempotent_replay' => $replay,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $r, bool $replay): array
    {
        return [
            'review_id' => (int) $r->id,
            'review_key' => (string) $r->review_key,
            'packet_id' => (int) $r->packet_id,
            'decision' => (string) $r->decision,
            'reason_code' => (string) $r->reason_code,
            'idempotent_replay' => $replay,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }
}
