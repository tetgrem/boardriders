<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only journal drafts. No orders/ledgers are modified. */
final class CheckoutCodJournalDraftService
{
    public const PREPARE_ABILITY = 'cod.journal-draft.prepare';
    public const REVIEW_ABILITY = 'cod.journal-draft.review';

    public function __construct(
        private readonly CheckoutCodJournalDraftEvaluator $evaluator
    ) {
    }

    /** @return array<string,mixed> */
    public function prepare(string $packetKey, string $operationKey): array
    {
        $actor = $this->actor(self::PREPARE_ABILITY);

        if (!Str::isUuid($packetKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD journal draft UUID');
        }

        $packetKey = strtolower($packetKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use ($packetKey, $operationKey, $actor): array {
            $view = $this->evaluator->inspect($packetKey, true);

            if (!$view['can_prepare']) {
                throw new DomainException(
                    'Bank coverage cannot be converted into journal draft'
                );
            }

            if (in_array($actor, $view['participants'], true)) {
                throw new DomainException(
                    'Independent journal draft author required'
                );
            }

            $signature = $this->digest([
                'cod-journal-draft-prepare-v1',
                $packetKey,
                $operationKey,
                $actor,
            ]);

            $old = DB::table('checkout_cod_journal_drafts')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                if ((int) $old->coverage_packet_id !== $view['packet_id']
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $signature
                    )
                    || !hash_equals(
                        (string) $old->snapshot_hmac,
                        $view['snapshot_hmac']
                    )) {
                    throw new DomainException(
                        'Journal UUID reused or source changed'
                    );
                }

                return $this->present($old, true);
            }

            if (DB::table('checkout_cod_journal_drafts')
                ->where('coverage_packet_id', $view['packet_id'])
                ->exists()) {
                throw new DomainException(
                    'Journal already drafted for this coverage packet'
                );
            }

            $id = DB::table('checkout_cod_journal_drafts')->insertGetId([
                'operation_key' => $operationKey,
                'coverage_packet_id' => $view['packet_id'],
                'source_kind' => $view['source_kind'],
                'source_id' => $view['source_id'],
                'orders_count' => $view['orders_count'],
                'entry_count' => $view['entries_count'],
                'gross_minor' => $view['gross_minor'],
                'fee_minor' => $view['fee_minor'],
                'bank_minor' => $view['bank_minor'],
                'snapshot_hmac' => $view['snapshot_hmac'],
                'prepared_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);

            foreach ($view['entries'] as $entry) {
                DB::table('checkout_cod_journal_draft_entries')->insert([
                    'draft_id' => $id,
                    'coverage_line_id' => $entry['coverage_line_id'],
                    'order_id' => $entry['order_id'],
                    'entry_kind' => $entry['entry_kind'],
                    'debit_account' => $entry['debit_account'],
                    'credit_account' => $entry['credit_account'],
                    'amount_minor' => $entry['amount_minor'],
                    'created_at' => now(),
                ]);
            }

            return $this->present(
                DB::table('checkout_cod_journal_drafts')
                    ->where('id', $id)
                    ->first(),
                false
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(
        string $draftKey,
        string $reviewKey,
        string $decision
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);

        if (!Str::isUuid($draftKey)
            || !Str::isUuid($reviewKey)
            || !in_array(
                $decision,
                ['retain_unposted', 'escalate_investigation'],
                true
            )) {
            throw new InvalidArgumentException(
                'Invalid journal draft review'
            );
        }

        $draftKey = strtolower($draftKey);
        $reviewKey = strtolower($reviewKey);

        $reason = $decision === 'retain_unposted'
            ? 'authenticated_payment_evidence_required'
            : 'journal_investigation_required';

        return DB::transaction(function () use (
            $draftKey,
            $reviewKey,
            $decision,
            $reason,
            $actor
        ): array {
            $draft = DB::table('checkout_cod_journal_drafts')
                ->where('operation_key', $draftKey)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw new DomainException('COD journal draft missing');
            }

            $packetKey = DB::table('checkout_cod_bank_coverage_packets')
                ->where('id', $draft->coverage_packet_id)
                ->value('operation_key');

            if ($packetKey === null) {
                throw new DomainException(
                    'Journal source packet missing'
                );
            }

            $view = $this->evaluator->inspect(
                (string) $packetKey,
                true
            );

            if ((int) $draft->prepared_by === $actor
                || in_array($actor, $view['participants'], true)) {
                throw new DomainException(
                    'Independent journal draft reviewer required'
                );
            }

            $signature = $this->digest([
                'cod-journal-draft-review-v1',
                $draftKey,
                $reviewKey,
                $actor,
                $decision,
                $reason,
                (string) $draft->request_hmac,
            ]);

            $old = DB::table('checkout_cod_journal_draft_reviews')
                ->where('draft_id', $draft->id)
                ->first();

            if ($old !== null) {
                if ($old->review_key !== $reviewKey
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $signature
                    )) {
                    throw new DomainException(
                        'Journal draft already reviewed'
                    );
                }

                return $this->presentReview($old, true);
            }

            $fresh = $view['can_prepare']
                && hash_equals(
                    (string) $draft->snapshot_hmac,
                    $view['snapshot_hmac']
                );

            if ($decision === 'retain_unposted' && !$fresh) {
                throw new DomainException(
                    'Stale journal draft cannot be retained'
                );
            }

            $id = DB::table('checkout_cod_journal_draft_reviews')
                ->insertGetId([
                    'review_key' => $reviewKey,
                    'draft_id' => $draft->id,
                    'decision' => $decision,
                    'reason_code' => $reason,
                    'reviewed_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->presentReview(
                DB::table('checkout_cod_journal_draft_reviews')
                    ->where('id', $id)
                    ->first(),
                false
            );
        }, 3);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if ((!is_int($id)
                && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException(
                'COD journal draft permission denied'
            );
        }

        return (int) $id;
    }

    private function digest(array $parts): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($parts, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string,mixed> */
    private function present(object $row, bool $replay): array
    {
        return [
            'draft_id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'coverage_packet_id' => (int) $row->coverage_packet_id,
            'source_kind' => (string) $row->source_kind,
            'source_id' => (int) $row->source_id,
            'orders_count' => (int) $row->orders_count,
            'entry_count' => (int) $row->entry_count,
            'gross_minor' => (int) $row->gross_minor,
            'fee_minor' => (int) $row->fee_minor,
            'bank_minor' => (int) $row->bank_minor,
            'idempotent_replay' => $replay,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $row, bool $replay): array
    {
        return [
            'review_id' => (int) $row->id,
            'review_key' => (string) $row->review_key,
            'draft_id' => (int) $row->draft_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'idempotent_replay' => $replay,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }
}
