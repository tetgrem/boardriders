<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only CRM projection. Never interprets an operator extract as an authenticated payment. */
final class CheckoutCodNovaPayRegisterStatusService
{
    public function __construct(private readonly CheckoutCodRefetchRiskService $risk)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(string $postingKey): array
    {
        $this->authorize();
        if (!Str::isUuid($postingKey)) {
            throw new InvalidArgumentException('Invalid bank posting UUID');
        }
        $posting = DB::table('checkout_cod_bank_receipt_postings')
            ->where('operation_key', strtolower($postingKey))->first();
        if ($posting === null) {
            throw new DomainException('Posted COD bank receipt not found');
        }
        $capture = DB::table('checkout_cod_novapay_register_captures')
            ->where('bank_posting_id', $posting->id)->first();
        if ($capture === null) {
            return [
                'status' => 'awaiting_novapay_register_extract',
                'bank_posting_id' => (int) $posting->id,
                'capture_id' => null,
                'register_allocations_matched' => false,
                'novapay_origin_authenticated' => false,
                'customer_payment_confirmed' => false,
                'cod_paid' => false,
            ];
        }
        $risk = $this->risk->inspect((string) $posting->source_kind, (int) $posting->source_id);
        $hold = (int) DB::selectOne(
                'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) '
                . 'THEN 1 ELSE 0 END AS held',
                [(string) $posting->source_kind, (int) $posting->source_id]
            )->held === 1;
        $reversal = DB::table('checkout_cod_bank_evidence_events')
                ->where('reference_hmac', $posting->reference_hmac)
                ->where('event_kind', 'reversal')->exists()
            || DB::table('checkout_cod_gateway_reports')
                ->where('reference_hmac', $posting->reference_hmac)
                ->where('event_kind', 'reversal_reported')->exists();
        $summary = DB::table('checkout_cod_novapay_register_lines')
            ->where('capture_id', $capture->id)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(gross_minor), 0) AS gross, '
                . 'COALESCE(SUM(fee_minor), 0) AS fee, COALESCE(SUM(net_minor), 0) AS net')
            ->first();
        $balanced = (int) $summary->n === (int) $capture->orders_count
            && (int) $summary->gross === (int) $capture->gross_minor
            && (int) $summary->fee === (int) $capture->fee_minor
            && (int) $summary->net === (int) $capture->bank_minor;
        $disputed = $risk['blocks_financial_approval'] || $hold || $reversal || !$balanced;
        return [
            'status' => $disputed
                ? 'novapay_register_claim_requires_investigation'
                : 'novapay_register_allocation_matched_origin_unverified',
            'bank_posting_id' => (int) $posting->id,
            'capture_id' => (int) $capture->id,
            'orders_count' => (int) $capture->orders_count,
            'gross_minor' => (int) $capture->gross_minor,
            'fee_minor' => (int) $capture->fee_minor,
            'bank_minor' => (int) $capture->bank_minor,
            'register_allocations_matched' => $balanced,
            'financial_hold' => $hold,
            'refetch_anomaly' => $risk['blocks_financial_approval'],
            'novapay_origin_authenticated' => false,
            'shipment_ownership_verified' => false,
            'customer_payment_confirmed' => false,
            'orders_payment_status_changed' => false,
            'cod_paid' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException('NovaPay register inspection denied');
        }
    }
}
