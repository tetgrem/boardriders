<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Internal read model shared by reconciliation and risk-status screens. */
final class CheckoutCodRefetchRiskService
{
    public function __construct(private readonly CheckoutCodRefetchRiskPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(string $sourceKind, int $sourceId): array
    {
        if (!in_array($sourceKind, ['single', 'batch'], true) || $sourceId <= 0) {
            throw new InvalidArgumentException('Invalid bank refetch risk source');
        }

        $rows = DB::table('checkout_cod_privat_refetches')
            ->where('source_kind', $sourceKind)
            ->where('source_id', $sourceId)
            ->orderBy('id')
            ->get(['id', 'result_code', 'statement_entry_id', 'row_hmac'])
            ->map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'result_code' => (string) $r->result_code,
                'statement_entry_id' => (int) $r->statement_entry_id,
                'row_hmac' => (string) $r->row_hmac,
            ])->all();

        return array_merge([
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
        ], $this->policy->assess($rows));
    }
}
