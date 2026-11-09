<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only financial reconciliation projection; no payment mutations. */
final class CheckoutCodSettlementStatusService
{
    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $this->authorize();

        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid COD order UUID');
        }

        $order = DB::table('orders')
            ->where('order_key', strtolower($orderKey))
            ->first(['id', 'payment_status']);

        if ($order === null) {
            throw new DomainException('COD order not found');
        }

        $case = DB::table('checkout_cod_settlement_cases')
            ->where('order_id', $order->id)
            ->first();

        $base = [
            'order_id' => (int) $order->id,
            'case_id' => $case === null ? null : (int) $case->id,
            'expected_cod_minor' => $case === null
                ? null : (int) $case->expected_cod_minor,
            'expected_fee_minor' => $case?->expected_fee_minor === null
                ? null : (int) $case->expected_fee_minor,
            'currency_code' => $case?->currency_code,
            'observations_count' => 0,
            'reviewed_count' => 0,
            'discrepancies_count' => 0,
            'latest_comparison' => null,
            'source_authenticity_verified' => false,
            'funds_received_verified' => false,
            'cod_paid_by_reconciliation' => false,
            'automatic_settlement_allowed' => false,
            'ttn_submission_allowed' => false,
        ];

        if ($case === null) {
            return $base + ['status' => 'no_settlement_case'];
        }

        $stats = DB::table('checkout_cod_settlement_observations as o')
            ->leftJoin(
                'checkout_cod_settlement_reviews as r',
                'r.observation_id',
                '=',
                'o.id'
            )
            ->where('o.case_id', $case->id)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COUNT(r.id) AS reviewed_count')
            ->selectRaw(
                "COUNT(*) FILTER (WHERE o.comparison_result IN (
                    'gross_mismatch',
                    'net_arithmetic_mismatch',
                    'fee_differs_from_estimate'
                )) AS discrepancy_count"
            )
            ->first();

        $latest = DB::table('checkout_cod_settlement_observations as o')
            ->leftJoin(
                'checkout_cod_settlement_reviews as r',
                'r.observation_id',
                '=',
                'o.id'
            )
            ->where('o.case_id', $case->id)
            ->orderByDesc('o.id')
            ->first([
                'o.comparison_result',
                'r.decision',
            ]);

        $count = (int) ($stats->total_count ?? 0);
        $reviewed = (int) ($stats->reviewed_count ?? 0);
        $discrepancies = (int) ($stats->discrepancy_count ?? 0);

        $state = match (true) {
            $count === 0 => 'awaiting_external_claim',
            $discrepancies > 0 => 'financial_discrepancy',
            $reviewed < $count => 'awaiting_independent_review',
            default => 'claims_reviewed_external_confirmation_required',
        };

        return array_merge($base, [
            'observations_count' => $count,
            'reviewed_count' => $reviewed,
            'discrepancies_count' => $discrepancies,
            'latest_comparison' => $latest?->comparison_result,
            'latest_review_decision' => $latest?->decision,
            'status' => $state,
        ]);
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Settlement status permission denied'
            );
        }
    }
}
