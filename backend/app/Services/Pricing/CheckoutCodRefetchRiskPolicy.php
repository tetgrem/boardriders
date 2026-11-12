<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Historical negative bank API observations cannot be cleared by a later retry. */
final class CheckoutCodRefetchRiskPolicy
{
    private const RESULTS = [
        'reference_not_found',
        'ambiguous_reference',
        'transaction_changed',
        'consistent_unverified',
    ];

    /**
     * @param list<array{id:int,result_code:string,statement_entry_id:int,row_hmac:string}> $observations
     * @return array<string,mixed>
     */
    public function assess(array $observations): array
    {
        $negative = 0;
        $lastId = 0;
        $lastCode = null;
        $digestRows = [];
        $negativeRows = [];

        foreach ($observations as $observation) {
            if (
                !is_array($observation)
                || !isset($observation['id'], $observation['statement_entry_id'],
                    $observation['result_code'], $observation['row_hmac'])
                || !is_int($observation['id']) || $observation['id'] <= $lastId
                || !is_int($observation['statement_entry_id'])
                || $observation['statement_entry_id'] <= 0
                || !is_string($observation['result_code'])
                || !in_array($observation['result_code'], self::RESULTS, true)
                || !is_string($observation['row_hmac'])
                || !preg_match('/^[a-f0-9]{64}$/D', $observation['row_hmac'])
            ) {
                throw new InvalidArgumentException('Invalid COD refetch risk history');
            }

            $lastId = $observation['id'];
            $lastCode = $observation['result_code'];
            $negative += $lastCode === 'consistent_unverified' ? 0 : 1;
            $digestRows[] = [
                $lastId,
                $observation['statement_entry_id'],
                $lastCode,
                $observation['row_hmac'],
            ];
            if ($lastCode !== 'consistent_unverified') {
                $negativeRows[] = $digestRows[count($digestRows) - 1];
            }
        }

        return [
            'status' => match (true) {
                $negative > 0 => 'bank_refetch_anomaly_unresolved',
                $lastId > 0 => 'api_rechecks_consistent_unverified',
                default => 'no_api_recheck_yet',
            },
            'observations_count' => count($observations),
            'negative_observations_count' => $negative,
            'latest_refetch_id' => $lastId > 0 ? $lastId : null,
            'latest_result_code' => $lastCode,
            'blocks_financial_approval' => $negative > 0,
            'snapshot_items' => $digestRows,
            'negative_snapshot_items' => $negativeRows,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }
}
