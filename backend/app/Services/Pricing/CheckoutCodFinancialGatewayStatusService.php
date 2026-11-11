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
 * Read-only signed-message inbox and correlation
 * with existing COD bank claims.
 */
final class CheckoutCodFinancialGatewayStatusService
{
    /** @return array<string,mixed> */
    public function inspect(
        string $sourceKind,
        string $operationKey
    ): array {
        $this->authorize();

        if (
            !in_array($sourceKind, ['single', 'batch'], true)
            || !Str::isUuid($operationKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid bank claim source'
            );
        }

        $table = $sourceKind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : 'checkout_cod_bank_batches';

        $source = DB::table($table)
            ->where('operation_key', strtolower($operationKey))
            ->first([
                'id',
                'reference_hmac',
                'claimed_net_minor',
            ]);

        if ($source === null) {
            throw new DomainException(
                'Bank claim does not exist'
            );
        }

        $reports = DB::table('checkout_cod_gateway_reports')
            ->where('reference_hmac', $source->reference_hmac)
            ->orderByDesc('id')
            ->get([
                'event_kind',
                'net_minor',
            ]);

        $credits = $reports->where(
            'event_kind',
            'credit_reported'
        );

        $reversals = $reports->where(
            'event_kind',
            'reversal_reported'
        );

        $mismatches = $credits->filter(
            fn (object $r): bool =>
                (int) $r->net_minor
                !== (int) $source->claimed_net_minor
        )->count();

        $status = match (true) {
            $reversals->isNotEmpty() =>
            'reversal_reported_requires_investigation',

            $mismatches > 0 =>
            'signed_amount_discrepancy_unverified',

            $credits->count() > 1 =>
            'multiple_signed_credits_require_investigation',

            $credits->count() === 1 =>
            'gateway_claim_correlated_unverified',

            default =>
            'awaiting_signed_gateway_message',
        };

        return [
            'source_kind' => $sourceKind,
            'source_id' => (int) $source->id,
            'status' => $status,
            'signed_reports_count' => $reports->count(),
            'credit_reports_count' => $credits->count(),
            'reversal_reports_count' => $reversals->count(),
            'amount_mismatches_count' => $mismatches,
            'gateway_message_authenticated' =>
                $reports->isNotEmpty(),
            'bank_origin_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function recent(
        int $limit = 25,
        ?int $beforeId = null
    ): array {
        $this->authorize();

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId < 1)
        ) {
            throw new InvalidArgumentException(
                'Invalid gateway inbox cursor'
            );
        }

        $query = DB::table('checkout_cod_gateway_reports as r')
            ->leftJoin(
                'checkout_cod_bank_reference_claims as c',
                'c.reference_hmac',
                '=',
                'r.reference_hmac'
            )
            ->orderByDesc('r.id');

        if ($beforeId !== null) {
            $query->where('r.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'r.id',
            'r.event_key',
            'r.event_kind',
            'r.net_minor',
            'c.source_kind',
            'c.source_id',
        ]);

        $hasMore = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $r): array => [
                'report_id' => (int) $r->id,
                'event_key' => (string) $r->event_key,
                'event_kind' => (string) $r->event_kind,
                'net_minor' => (int) $r->net_minor,
                'currency_code' => 'UAH',
                'linked_source_kind' => $r->source_kind,
                'linked_source_id' => $r->source_id === null
                    ? null
                    : (int) $r->source_id,
                'bank_origin_authenticated' => false,
                'funds_received_verified' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['report_id']
                : null,
            'automatic_settlement_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(
                    is_string($id) && ctype_digit($id)
                ))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Gateway financial inbox permission denied'
            );
        }
    }
}
