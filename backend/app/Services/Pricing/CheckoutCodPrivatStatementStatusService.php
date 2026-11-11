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
 * PII-free candidate correlation.
 * An imported statement does not mark a COD order paid.
 */
final class CheckoutCodPrivatStatementStatusService
{
    /** @return array<string,mixed> */
    public function inspect(
        string $kind,
        string $sourceKey
    ): array {
        $this->authorize();

        if (
            !in_array($kind, ['single', 'batch'], true)
            || !Str::isUuid($sourceKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid bank claim key'
            );
        }

        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : 'checkout_cod_bank_batches';

        $source = DB::table($table)
            ->where(
                'operation_key',
                strtolower($sourceKey)
            )
            ->first([
                'id',
                'reference_hmac',
                'claimed_net_minor',
            ]);

        if ($source === null) {
            throw new DomainException(
                'COD bank claim missing'
            );
        }

        return array_merge([
            'source_kind' => $kind,
            'source_id' => (int) $source->id,
        ], $this->snapshot(
            (string) $source->reference_hmac,
            (int) $source->claimed_net_minor
        ));
    }

    /** @return array<string,mixed> */
    public function snapshot(
        string $referenceHmac,
        int $claimedMinor
    ): array {
        $this->authorize();

        if (
            !preg_match(
                '/^[a-f0-9]{64}$/D',
                $referenceHmac
            )
            || $claimedMinor < 0
            || $claimedMinor > 100000000
        ) {
            throw new InvalidArgumentException(
                'Invalid bank claim snapshot'
            );
        }

        $alias = (string) config(
            'services.privatbank_autoclient.account_alias',
            ''
        );

        if (!preg_match(
            '/^[a-z][a-z0-9_-]{0,47}$/D',
            $alias
        )) {
            throw new DomainException(
                'Statement account alias not configured'
            );
        }

        $totals = DB::table(
            'checkout_cod_privat_statement_entries'
        )
            ->where('account_alias', $alias)
            ->where('reference_hmac', $referenceHmac)
            ->selectRaw(
                "COUNT(*) AS total, "
                . "COUNT(*) FILTER (WHERE direction = 'credit') AS credits, "
                . "COUNT(*) FILTER (WHERE direction = 'debit') AS debits, "
                . "COUNT(*) FILTER ("
                . "WHERE direction = 'credit' AND amount_minor <> ?"
                . ") AS mismatches",
                [$claimedMinor]
            )
            ->first();

        $credits = (int) $totals->credits;
        $debits = (int) $totals->debits;
        $mismatches = (int) $totals->mismatches;

        $alert = $debits > 0
            || $mismatches > 0
            || $credits > 1;

        return [
            'status' => match (true) {
                $debits > 0 =>
                'bank_debit_requires_investigation',
                $mismatches > 0 =>
                'bank_statement_amount_discrepancy',
                $credits > 1 =>
                'multiple_bank_credits_require_investigation',
                $credits === 1 =>
                'bank_statement_candidate_unverified',
                default =>
                'awaiting_bank_statement',
            },
            'transactions_count' => (int) $totals->total,
            'credit_transactions_count' => $credits,
            'debit_transactions_count' => $debits,
            'amount_mismatches_count' => $mismatches,
            'requires_investigation' => $alert,
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
                'Invalid statement queue cursor'
            );
        }

        $query = DB::table(
            'checkout_cod_privat_statement_entries as e'
        )
            ->leftJoin(
                'checkout_cod_bank_reference_claims as c',
                'c.reference_hmac',
                '=',
                'e.reference_hmac'
            )
            ->orderByDesc('e.id');

        if ($beforeId !== null) {
            $query->where('e.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'e.id',
            'e.account_alias',
            'e.direction',
            'e.amount_minor',
            'e.booked_on',
            'c.source_kind',
            'c.source_id',
        ]);

        $more = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $r): array => [
                'entry_id' => (int) $r->id,
                'account_alias' => (string) $r->account_alias,
                'direction' => (string) $r->direction,
                'amount_minor' => (int) $r->amount_minor,
                'booked_on' => (string) $r->booked_on,
                'linked_source_kind' => $r->source_kind,
                'linked_source_id' => $r->source_id === null
                    ? null
                    : (int) $r->source_id,
                'cod_paid' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['entry_id']
                : null,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id)
                && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Bank statement read permission denied'
            );
        }
    }
}
