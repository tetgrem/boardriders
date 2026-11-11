<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** PII-free projection of signed provider evidence; still no payment posting. */
final class CheckoutCodBankEvidenceStatusService
{
    /** @return array<string,mixed> */
    public function inspect(string $kind, string $sourceKey): array
    {
        $this->authorize();
        if (!in_array($kind, ['single', 'batch'], true) || !Str::isUuid($sourceKey)) {
            throw new InvalidArgumentException('Invalid bank claim key');
        }

        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : 'checkout_cod_bank_batches';
        $source = DB::table($table)->where('operation_key', strtolower($sourceKey))
            ->first(['id', 'reference_hmac', 'claimed_net_minor']);
        if ($source === null) {
            throw new DomainException('Bank claim not found');
        }

        return array_merge([
            'source_kind' => $kind,
            'source_id' => (int) $source->id,
        ], $this->snapshot((string) $source->reference_hmac, (int) $source->claimed_net_minor));
    }

    /** @return array<string,mixed> */
    public function snapshot(string $referenceHmac, int $claimedMinor): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $referenceHmac)
            || $claimedMinor < 0 || $claimedMinor > 100000000) {
            throw new InvalidArgumentException('Invalid bank evidence snapshot');
        }

        $totals = DB::table('checkout_cod_bank_evidence_events')
            ->where('reference_hmac', $referenceHmac)
            ->selectRaw(
                "COUNT(*) AS total, " .
                "COUNT(*) FILTER (WHERE event_kind = 'credit') AS credits, " .
                "COUNT(*) FILTER (WHERE event_kind = 'reversal') AS reversals, " .
                "COUNT(*) FILTER (WHERE event_kind = 'credit' AND amount_minor <> ?) AS mismatches",
                [$claimedMinor]
            )->first();

        $credits = (int) $totals->credits;
        $reversals = (int) $totals->reversals;
        $mismatches = (int) $totals->mismatches;

        $status = match (true) {
            $reversals > 0 => 'signed_reversal_requires_investigation',
            $mismatches > 0 => 'signed_amount_discrepancy',
            $credits > 1 => 'multiple_signed_credits',
            $credits === 1 => 'signature_correlated_payment_unverified',
            default => 'awaiting_pinned_source_evidence',
        };

        return [
            'status' => $status,
            'events_count' => (int) $totals->total,
            'credit_events_count' => $credits,
            'reversal_events_count' => $reversals,
            'amount_mismatches_count' => $mismatches,
            'requires_investigation' => $reversals > 0 || $mismatches > 0 || $credits > 1,
            'provider_signature_verified' => (int) $totals->total > 0,
            'bank_origin_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function recent(int $limit = 25, ?int $beforeId = null): array
    {
        $this->authorize();
        if ($limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('Invalid evidence queue cursor');
        }

        $query = DB::table('checkout_cod_bank_evidence_events as e')
            ->leftJoin('checkout_cod_bank_reference_claims as c',
                'c.reference_hmac', '=', 'e.reference_hmac')
            ->orderByDesc('e.id');
        if ($beforeId !== null) {
            $query->where('e.id', '<', $beforeId);
        }
        $rows = $query->limit($limit + 1)->get([
            'e.id', 'e.event_key', 'e.provider_id', 'e.event_kind',
            'e.amount_minor', 'c.source_kind', 'c.source_id',
        ]);
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'event_key' => (string) $r->event_key,
            'provider_id' => (string) $r->provider_id,
            'event_kind' => (string) $r->event_kind,
            'amount_minor' => (int) $r->amount_minor,
            'currency_code' => 'UAH',
            'source_kind' => $r->source_kind,
            'source_id' => $r->source_id === null ? null : (int) $r->source_id,
            'funds_received_verified' => false,
        ])->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['id'] : null,
            'automatic_settlement_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException('Bank evidence read permission denied');
        }
    }
}
