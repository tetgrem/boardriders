<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only, PII-free current exception state across Blocks 23–25. */
final class CheckoutCodFinancialExceptionStatusService
{
    public function __construct(
        private readonly CheckoutCodFinancialExceptionPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $sourceKind, string $sourceKey): array
    {
        $this->authorize();

        if (
            !in_array(
                $sourceKind,
                CheckoutCodFinancialExceptionPolicy::SOURCES,
                true
            )
            || !Str::isUuid($sourceKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid exception source'
            );
        }

        $table = match ($sourceKind) {
            'single' => 'checkout_cod_settlement_bank_receipts',
            'batch' => 'checkout_cod_bank_batches',
            'novapay' => 'checkout_cod_settlement_observations',
        };

        $id = DB::table($table)
            ->where('operation_key', strtolower($sourceKey))
            ->value('id');

        if ($id === null) {
            throw new DomainException(
                'Financial source not found'
            );
        }

        $latest = DB::table('checkout_cod_financial_exceptions as e')
            ->leftJoin(
                'checkout_cod_financial_exception_reviews as r',
                'r.exception_id',
                '=',
                'e.id'
            )
            ->where('e.source_kind', $sourceKind)
            ->where('e.source_id', $id)
            ->orderByDesc('e.id')
            ->first([
                'e.exception_key',
                'e.reason_code',
                'r.decision',
            ]);

        $status = $latest === null
            ? 'no_exception'
            : $this->policy->status($latest->decision);

        return [
            'source_kind' => $sourceKind,
            'source_id' => (int) $id,
            'exception_key' => $latest?->exception_key,
            'reason_code' => $latest?->reason_code,
            'status' => $status,
            'financial_hold' => $latest !== null
                && $latest->decision !== 'dismiss_report',
            'bank_source_authenticated' => false,
            'cod_paid_by_exception' => false,
            'refund_executed' => false,
        ];
    }

    /** Is anything linked to this COD order disputed? */
    public function hasCaseHold(int $caseId): bool
    {
        $this->authorize();

        if ($caseId <= 0) {
            throw new InvalidArgumentException(
                'Invalid settlement case id'
            );
        }

        return DB::table('checkout_cod_settlement_bank_receipts')
                ->where('case_id', $caseId)
                ->whereRaw(
                    "boardriders_cod_financial_has_hold('single', id)"
                )
                ->exists()

            || DB::table('checkout_cod_settlement_observations')
                ->where('case_id', $caseId)
                ->whereRaw(
                    "boardriders_cod_financial_has_hold('novapay', id)"
                )
                ->exists()

            || DB::table('checkout_cod_bank_batch_allocations')
                ->where('case_id', $caseId)
                ->whereRaw(
                    "boardriders_cod_financial_has_hold('batch', batch_id)"
                )
                ->exists();
    }

    /** A batch inherits holds from NovaPay sources it contains. */
    public function hasBatchHold(int $batchId): bool
    {
        $this->authorize();

        if ($batchId <= 0) {
            throw new InvalidArgumentException(
                'Invalid bank batch id'
            );
        }

        $ownHold = DB::table('checkout_cod_bank_batches')
            ->where('id', $batchId)
            ->whereRaw(
                "boardriders_cod_financial_has_hold('batch', id)"
            )
            ->exists();

        return $ownHold

            || DB::table('checkout_cod_bank_batch_allocations')
                ->where('batch_id', $batchId)
                ->whereRaw(
                    "boardriders_cod_financial_has_hold('novapay', observation_id)"
                )
                ->exists();
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
                'Financial exception read permission denied'
            );
        }
    }
}
