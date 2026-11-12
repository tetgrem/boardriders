<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** A later clean recheck does not undo a posting; a later anomaly flags it. */
final class CheckoutCodBankReceiptPostingStatusService
{
    public function __construct(private readonly CheckoutCodRefetchRiskService $risk)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(string $journalKey): array
    {
        $this->authorize();
        if (!Str::isUuid($journalKey)) {
            throw new InvalidArgumentException('Invalid bank receipt journal UUID');
        }
        $draft = DB::table('checkout_cod_journal_drafts')
            ->where('operation_key', strtolower($journalKey))->first(['id']);
        if ($draft === null) {
            throw new DomainException('COD journal missing');
        }
        $posted = DB::table('checkout_cod_bank_receipt_postings')
            ->where('journal_draft_id', $draft->id)->first();
        if ($posted === null) {
            return [
                'status' => 'bank_receipt_not_posted',
                'posting_id' => null,
                'bank_credit_booked_in_internal_ledger' => false,
                'cod_paid' => false,
            ];
        }
        $risk = $this->risk->inspect((string) $posted->source_kind, (int) $posted->source_id);
        $hold = (int) DB::selectOne(
                'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) '
                . 'THEN 1 ELSE 0 END AS held',
                [$posted->source_kind, $posted->source_id]
            )->held === 1;
        $reversal = DB::table('checkout_cod_bank_evidence_events')
                ->where('reference_hmac', $posted->reference_hmac)->where('event_kind', 'reversal')
                ->exists() || DB::table('checkout_cod_gateway_reports')
                ->where('reference_hmac', $posted->reference_hmac)->where('event_kind', 'reversal_reported')
                ->exists();
        $disputed = $risk['blocks_financial_approval'] || $hold || $reversal;
        return [
            'status' => $disputed
                ? 'posted_bank_credit_requires_investigation'
                : 'bank_credit_posted',
            'posting_id' => (int) $posted->id,
            'amount_minor' => (int) $posted->amount_minor,
            'currency_code' => 'UAH',
            'financial_hold' => $hold,
            'refetch_risk_detected' => $risk['blocks_financial_approval'],
            'bank_credit_booked_in_internal_ledger' => true,
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
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException('COD bank receipt audit read denied');
        }
    }
}
