<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only per-order COD posting preflight.
 *
 * No financial ledger mutation.
 * No order payment status mutation.
 */
final class CheckoutCodPostingPreflightService
{
    public const PREPARE_ABILITY = 'cod.posting-preflight.prepare';
    public const REVIEW_ABILITY = 'cod.posting-preflight.review';

    public function __construct(
        private readonly CheckoutCodPostingPreflightEvaluator $evaluator
    ) {
    }

    /** @return array<string, mixed> */
    public function prepare(
        string $reconciliationKey,
        string $orderKey,
        string $operationKey
    ): array {
        $actor = $this->actor(self::PREPARE_ABILITY);

        if (
            !Str::isUuid($reconciliationKey)
            || !Str::isUuid($orderKey)
            || !Str::isUuid($operationKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid COD posting preflight UUID'
            );
        }

        $reconciliationKey = strtolower($reconciliationKey);
        $orderKey = strtolower($orderKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor,
            $reconciliationKey,
            $orderKey,
            $operationKey
        ): array {
            $current = $this->evaluator->evaluate(
                $reconciliationKey,
                $orderKey,
                true
            );

            if (!$current['can_prepare_review']) {
                throw new DomainException(
                    'COD posting preflight is not eligible'
                );
            }

            if (in_array($actor, $current['participants'], true)) {
                throw new DomainException(
                    'Independent posting preflight author required'
                );
            }

            $requestHmac = $this->hmac([
                'cod-posting-preflight-v1',
                $reconciliationKey,
                $orderKey,
                $operationKey,
                $actor,
            ]);

            $existing = DB::table(
                'checkout_cod_posting_preflights'
            )
                ->where('operation_key', $operationKey)
                ->first();

            if ($existing !== null) {
                if (
                    (int) $existing->reconciliation_id
                    !== $current['reconciliation_id']
                    || (int) $existing->order_id
                    !== $current['order_id']
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $requestHmac
                    )
                    || !hash_equals(
                        (string) $existing->snapshot_hmac,
                        $current['snapshot_hmac']
                    )
                ) {
                    throw new DomainException(
                        'COD preflight UUID reused or snapshot changed'
                    );
                }

                return $this->present($existing, true);
            }

            $already = DB::table(
                'checkout_cod_posting_preflights'
            )
                ->where(
                    'reconciliation_id',
                    $current['reconciliation_id']
                )
                ->where('order_id', $current['order_id'])
                ->exists();

            if ($already) {
                throw new DomainException(
                    'COD preflight already prepared for this revision and order'
                );
            }

            $id = DB::table(
                'checkout_cod_posting_preflights'
            )->insertGetId([
                'operation_key' => $operationKey,
                'reconciliation_id' =>
                    $current['reconciliation_id'],
                'order_id' => $current['order_id'],
                'source_kind' => $current['source_kind'],
                'source_id' => $current['source_id'],
                'statement_entry_id' =>
                    $current['statement_entry_id'],
                'case_id' => $current['case_id'],
                'observation_id' => $current['observation_id'],
                'cod_gross_minor' =>
                    $current['observed_cod_minor'],
                'fee_minor' => $current['fee_minor'],
                'bank_allocation_minor' =>
                    $current['bank_allocation_minor'],
                'snapshot_hmac' => $current['snapshot_hmac'],
                'prepared_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_posting_preflights')
                    ->where('id', $id)
                    ->first(),
                false
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    public function review(
        string $preflightKey,
        string $reviewKey,
        string $decision
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);

        if (
            !Str::isUuid($preflightKey)
            || !Str::isUuid($reviewKey)
            || !in_array(
                $decision,
                [
                    'retain_unverified',
                    'hold_for_investigation',
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid COD posting preflight review'
            );
        }

        $preflightKey = strtolower($preflightKey);
        $reviewKey = strtolower($reviewKey);

        return DB::transaction(function () use (
            $actor,
            $preflightKey,
            $reviewKey,
            $decision
        ): array {
            $preflight = DB::table(
                'checkout_cod_posting_preflights'
            )
                ->where('operation_key', $preflightKey)
                ->lockForUpdate()
                ->first();

            if ($preflight === null) {
                throw new DomainException(
                    'COD posting preflight not found'
                );
            }

            $reconciliationKey = DB::table(
                'checkout_cod_statement_reconciliations'
            )
                ->where('id', $preflight->reconciliation_id)
                ->value('operation_key');

            $orderKey = DB::table('orders')
                ->where('id', $preflight->order_id)
                ->value('order_key');

            if ($reconciliationKey === null || $orderKey === null) {
                throw new DomainException(
                    'COD preflight source missing'
                );
            }

            $current = $this->evaluator->evaluate(
                (string) $reconciliationKey,
                (string) $orderKey,
                true
            );

            if (
                (int) $preflight->prepared_by === $actor
                || in_array(
                    $actor,
                    $current['participants'],
                    true
                )
            ) {
                throw new DomainException(
                    'Independent COD preflight reviewer required'
                );
            }

            $reason = $decision === 'retain_unverified'
                ? 'external_payment_evidence_required'
                : 'posting_preflight_investigation';

            $signature = $this->hmac([
                'cod-posting-preflight-review-v1',
                $preflightKey,
                $reviewKey,
                $actor,
                $decision,
                $reason,
                (string) $preflight->request_hmac,
            ]);

            $existing = DB::table(
                'checkout_cod_posting_preflight_reviews'
            )
                ->where('preflight_id', $preflight->id)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->review_key !== $reviewKey
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $signature
                    )
                ) {
                    throw new DomainException(
                        'COD preflight already reviewed'
                    );
                }

                return $this->presentReview($existing, true);
            }

            $fresh = hash_equals(
                (string) $preflight->snapshot_hmac,
                (string) $current['snapshot_hmac']
            );

            if (
                $decision === 'retain_unverified'
                && (!$fresh || !$current['can_prepare_review'])
            ) {
                throw new DomainException(
                    'Stale or unsafe COD preflight cannot be retained'
                );
            }

            $id = DB::table(
                'checkout_cod_posting_preflight_reviews'
            )->insertGetId([
                'review_key' => $reviewKey,
                'preflight_id' => $preflight->id,
                'decision' => $decision,
                'reason_code' => $reason,
                'reviewed_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);

            return $this->presentReview(
                DB::table(
                    'checkout_cod_posting_preflight_reviews'
                )->where('id', $id)->first(),
                false
            );
        }, 3);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id)
                && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException(
                'COD posting preflight permission denied'
            );
        }

        return (int) $id;
    }

    private function hmac(array $payload): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string, mixed> */
    private function present(object $row, bool $replay): array
    {
        return [
            'preflight_id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'reconciliation_id' => (int) $row->reconciliation_id,
            'order_id' => (int) $row->order_id,
            'source_kind' => (string) $row->source_kind,
            'source_id' => (int) $row->source_id,
            'cod_gross_minor' => (int) $row->cod_gross_minor,
            'fee_minor' => (int) $row->fee_minor,
            'bank_allocation_minor' =>
                (int) $row->bank_allocation_minor,
            'idempotent_replay' => $replay,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function presentReview(
        object $row,
        bool $replay
    ): array {
        return [
            'review_id' => (int) $row->id,
            'review_key' => (string) $row->review_key,
            'preflight_id' => (int) $row->preflight_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'idempotent_replay' => $replay,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }
}
