<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Redacted read-only CRM projection of unresolved bank refetch observations. */
final class CheckoutCodRefetchRiskStatusService
{
    public function __construct(private readonly CheckoutCodRefetchRiskService $risk)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(string $kind, string $sourceKey): array
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException('COD refetch risk read permission denied');
        }

        if (!in_array($kind, ['single', 'batch'], true) || !Str::isUuid($sourceKey)) {
            throw new InvalidArgumentException('Invalid COD refetch risk source');
        }

        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : 'checkout_cod_bank_batches';
        $sourceId = DB::table($table)
            ->where('operation_key', strtolower($sourceKey))
            ->value('id');
        if ($sourceId === null) {
            throw new DomainException('COD refetch risk source missing');
        }

        $view = $this->risk->inspect($kind, (int) $sourceId);
        unset($view['snapshot_items'], $view['negative_snapshot_items']);

        return $view;
    }
}
