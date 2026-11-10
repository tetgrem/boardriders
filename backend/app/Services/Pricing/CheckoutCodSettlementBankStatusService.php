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
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid_by_reconciliation' => false,
            'automatic_settlement_allowed' => false,
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

        $status = match (true) {
            $financialHold => 'financial_exception_hold',
            $bankCount === 0 => 'awaiting_bank_claim',
            $matchCount === 0 => 'awaiting_bank_matching',
            $mismatchCount > 0 => 'bank_discrepancy_requires_investigation',
            $reviewCount < $matchCount => 'awaiting_independent_bank_review',
            default => 'claims_correlated_external_confirmation_required',
        };

        return array_merge($base, [
            'status' => $status,
            'financial_exception_hold' => $financialHold,
            'bank_claims_count' => $bankCount,
            'match_proposals_count' => $matchCount,
            'match_reviews_count' => $reviewCount,
            'mismatches_count' => $mismatchCount,
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
