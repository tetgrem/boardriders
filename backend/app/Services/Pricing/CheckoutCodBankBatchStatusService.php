<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** PII-free batch status and allocation totals; no financial posting. */
final class CheckoutCodBankBatchStatusService
{
    public function __construct(
        private readonly CheckoutCodBankBatchPolicy $policy
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $batchKey): array
    {
        $this->authorize();

        if (!Str::isUuid($batchKey)) {
            throw new InvalidArgumentException('Invalid bank batch UUID');
        }

        $batch = DB::table('checkout_cod_bank_batches')
            ->where('operation_key', strtolower($batchKey))
            ->first();

        if ($batch === null) {
            throw new DomainException('Bank batch not found');
        }

        $totals = DB::table('checkout_cod_bank_batch_allocations')
            ->where('batch_id', $batch->id)
            ->selectRaw(
                'COUNT(*) AS count, COALESCE(SUM(amount_minor), 0) AS amount'
            )
            ->first();

        $view = $this->policy->evaluate(
            (int) $batch->claimed_net_minor,
            (int) $totals->amount,
            (int) $totals->count
        );

        $review = DB::table('checkout_cod_bank_batch_reviews')
            ->where('batch_id', $batch->id)
            ->first(['decision', 'reviewed_by']);


        $financialHold = app(
            CheckoutCodFinancialExceptionStatusService::class
        )->hasBatchHold((int) $batch->id);

        $status = $review === null
            ? (
            (int) $totals->count === 0
                ? 'awaiting_allocations'
                : 'awaiting_independent_review'
            )
            : 'reviewed_external_confirmation_required';

        if ($financialHold) {
            $status = 'financial_exception_hold';
        }


        $gatewayReportsCount = DB::table(
            'checkout_cod_gateway_reports'
        )
            ->where('reference_hmac', $batch->reference_hmac)
            ->count();

        $gatewayReversal = DB::table(
            'checkout_cod_gateway_reports'
        )
            ->where('reference_hmac', $batch->reference_hmac)
            ->where('event_kind', 'reversal_reported')
            ->exists();

        if (!$financialHold && $gatewayReversal) {
            $status = 'gateway_reversal_reported_requires_investigation';
        }

        $bankEvidence = app(
            CheckoutCodBankEvidenceStatusService::class
        )->snapshot(
            (string) $batch->reference_hmac,
            (int) $batch->claimed_net_minor
        );

        if (
            !$financialHold
            && !$gatewayReversal
            && $bankEvidence['requires_investigation']
        ) {
            $status = 'bank_evidence_requires_investigation';
        }

        $privatbank = app(
            CheckoutCodPrivatStatementStatusService::class
        )->snapshot(
            (string) $batch->reference_hmac,
            (int) $batch->claimed_net_minor
        );

        if (
            !$financialHold
            && !$gatewayReversal
            && !$bankEvidence['requires_investigation']
            && $privatbank['requires_investigation']
        ) {
            $status = 'privatbank_statement_requires_investigation';
        }

        return array_merge($view, [
            'batch_id' => (int) $batch->id,
            'operation_key' => (string) $batch->operation_key,
            'currency_code' => 'UAH',
            'status' => $status,
            'financial_exception_hold' => $financialHold,
            'gateway_signed_reports_count' => $gatewayReportsCount,
            'gateway_reversal_reported' => $gatewayReversal,
            'review_decision' => $review?->decision,
            'sealed' => $review !== null,
            'bank_evidence_alert' => $bankEvidence['requires_investigation'],
            'bank_evidence_events_count' => $bankEvidence['events_count'],
            'privatbank_statement_alert' => $privatbank['requires_investigation'],
            'privatbank_transactions_count' => $privatbank['transactions_count'],
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
                'Bank batch status permission denied'
            );
        }
    }
}
