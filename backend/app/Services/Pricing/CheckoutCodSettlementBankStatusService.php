<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** PII-free reconciliation dashboard; never claims money was transferred. */
final class CheckoutCodSettlementBankStatusService
{
    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $this->authorize();
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid order UUID');
        }
        $order = DB::table('orders')
            ->where('order_key', strtolower($orderKey))->first(['id']);
        if ($order === null) {
            throw new DomainException('Order not found');
        }

        $case = DB::table('checkout_cod_settlement_cases')
            ->where('order_id', $order->id)->first(['id']);

        $base = [
            'order_id' => (int) $order->id,
            'case_id' => $case === null ? null : (int) $case->id,
            'bank_claims_count' => 0,
            'match_proposals_count' => 0,
            'match_reviews_count' => 0,
            'mismatches_count' => 0,
            'gateway_signed_reports_count' => 0,
            'gateway_reversal_reported' => false,
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid_by_reconciliation' => false,
            'automatic_settlement_allowed' => false,
            'bank_evidence_alert' => false,
            'privatbank_statement_alert' => false,
        ];
        if ($case === null) {
            return $base + ['status' => 'no_settlement_case'];
        }

        $bankCount = DB::table('checkout_cod_settlement_bank_receipts')
            ->where('case_id', $case->id)->count();
        $matches = DB::table('checkout_cod_settlement_bank_matches')
            ->where('case_id', $case->id);
        $matchCount = (clone $matches)->count();
        $mismatchCount = (clone $matches)
            ->where('match_result', '<>', 'amounts_match_unverified')->count();
        $reviewCount = DB::table('checkout_cod_settlement_bank_reviews as r')
            ->join('checkout_cod_settlement_bank_matches as m',
                'm.id', '=', 'r.match_id')
            ->where('m.case_id', $case->id)->count();


        $financialHold = app(
            CheckoutCodFinancialExceptionStatusService::class
        )->hasCaseHold((int) $case->id);

        $gatewayReportsCount = DB::table(
            'checkout_cod_gateway_reports as g'
        )
            ->join(
                'checkout_cod_settlement_bank_receipts as b',
                'b.reference_hmac',
                '=',
                'g.reference_hmac'
            )
            ->where('b.case_id', $case->id)
            ->count();

        $gatewayReversal = DB::table(
            'checkout_cod_gateway_reports as g'
        )
            ->join(
                'checkout_cod_settlement_bank_receipts as b',
                'b.reference_hmac',
                '=',
                'g.reference_hmac'
            )
            ->where('b.case_id', $case->id)
            ->where('g.event_kind', 'reversal_reported')
            ->exists();

        $bankEvidenceAlert = DB::table(
            'checkout_cod_settlement_bank_receipts as b'
        )
            ->where('b.case_id', $case->id)
            ->whereRaw(<<<'SQL'
        EXISTS (
            SELECT 1
            FROM checkout_cod_bank_evidence_events e
            WHERE e.reference_hmac = b.reference_hmac
            HAVING
                COUNT(*) FILTER (
                    WHERE e.event_kind = 'reversal'
                ) > 0
                OR COUNT(*) FILTER (
                    WHERE e.event_kind = 'credit'
                      AND e.amount_minor <> b.claimed_net_minor
                ) > 0
                OR COUNT(*) FILTER (
                    WHERE e.event_kind = 'credit'
                ) > 1
        )
    SQL)
            ->exists();


        $privatbankAlert = DB::table(
            'checkout_cod_privat_statement_entries as e'
        )
            ->join(
                'checkout_cod_settlement_bank_receipts as b',
                'b.reference_hmac',
                '=',
                'e.reference_hmac'
            )
            ->where('b.case_id', $case->id)
            ->selectRaw('b.id')
            ->groupBy('b.id', 'b.claimed_net_minor')
            ->havingRaw(<<<'SQL'
        COUNT(*) FILTER (
            WHERE e.direction = 'debit'
        ) > 0
        OR COUNT(*) FILTER (
            WHERE e.direction = 'credit'
              AND e.amount_minor <> b.claimed_net_minor
        ) > 0
        OR COUNT(*) FILTER (
            WHERE e.direction = 'credit'
        ) > 1
    SQL)
            ->exists();


        $status = match (true) {
            $financialHold => 'financial_exception_hold',
            $gatewayReversal =>
            'gateway_reversal_reported_requires_investigation',
            $bankEvidenceAlert => 'bank_evidence_requires_investigation',
            $privatbankAlert =>
            'privatbank_statement_requires_investigation',
            $bankCount === 0 => 'awaiting_bank_claim',
            $matchCount === 0 => 'awaiting_bank_matching',
            $mismatchCount > 0 => 'bank_discrepancy_requires_investigation',
            $reviewCount < $matchCount => 'awaiting_independent_bank_review',
            default => 'claims_correlated_external_confirmation_required',
        };

        return array_merge($base, [
            'status' => $status,
            'financial_exception_hold' => $financialHold,
            'gateway_signed_reports_count' => $gatewayReportsCount,
            'gateway_reversal_reported' => $gatewayReversal,
            'bank_claims_count' => $bankCount,
            'match_proposals_count' => $matchCount,
            'match_reviews_count' => $reviewCount,
            'mismatches_count' => $mismatchCount,
            'bank_evidence_alert' => $bankEvidenceAlert,
            'privatbank_statement_alert' => $privatbankAlert,
        ]);

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
            throw new AuthorizationException('COD bank status permission denied');
        }
    }
}
