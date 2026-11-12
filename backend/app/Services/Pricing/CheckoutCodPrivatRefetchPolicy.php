<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Cross-check one originally imported posted transaction against a fresh API response. */
final class CheckoutCodPrivatRefetchPolicy
{
    /** @param array<int,array<string,mixed>> $rows
     *  @return array{result_code:string,reference_rows:int,identity_rows:int,exact_rows:int,bank_api_read_success:bool,bank_native_signature_verified:bool,funds_received_verified:bool,cod_paid:bool}
     */
    public function compare(
        array $rows,
        string $alias,
        string $referenceHmac,
        string $transactionHmac,
        string $rowHmac,
        int $claimedMinor,
        string $bookedOn,
        string $appKey
    ): array {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $alias)
            || !preg_match('/^[a-f0-9]{64}$/D', $referenceHmac)
            || !preg_match('/^[a-f0-9]{64}$/D', $transactionHmac)
            || !preg_match('/^[a-f0-9]{64}$/D', $rowHmac)
            || $claimedMinor < 1 || $claimedMinor > 100000000
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $bookedOn)
            || $appKey === '' || count($rows) > 2000) {
            throw new InvalidArgumentException('Invalid COD bank refetch comparison');
        }
        $referenceRows = 0;
        $identityRows = 0;
        $exactRows = 0;
        foreach ($rows as $row) {
            if (!is_array($row)
                || !isset($row['ref'], $row['refn'], $row['direction'],
                    $row['amount_minor'], $row['booked_on'])
                || !is_string($row['ref']) || !is_string($row['refn'])
                || !in_array($row['direction'], ['credit', 'debit'], true)
                || !is_int($row['amount_minor']) || $row['amount_minor'] < 1
                || !is_string($row['booked_on'])) {
                throw new InvalidArgumentException('Unexpected normalized bank refetch row');
            }
            if (!hash_equals($referenceHmac, $this->digest(
                ['cod-bank-reference-v1', $row['ref']], $appKey
            ))) {
                continue;
            }
            ++$referenceRows;
            if (!hash_equals($transactionHmac, $this->digest(
                ['cod-privatbank-tx-v1', $alias, $row['ref'], $row['refn']], $appKey
            ))) {
                continue;
            }
            ++$identityRows;
            $newRowHmac = $this->digest([
                'cod-privatbank-row-v1', $alias, $row['ref'], $row['refn'],
                $row['direction'], $row['amount_minor'], $row['booked_on'],
            ], $appKey);
            if ($row['direction'] === 'credit'
                && $row['amount_minor'] === $claimedMinor
                && $row['booked_on'] === $bookedOn
                && hash_equals($rowHmac, $newRowHmac)) {
                ++$exactRows;
            }
        }
        $status = match (true) {
            $referenceRows === 0 => 'reference_not_found',
            $referenceRows > 1 => 'ambiguous_reference',
            $identityRows !== 1 || $exactRows !== 1 => 'transaction_changed',
            default => 'consistent_unverified',
        };
        return [
            'result_code' => $status,
            'reference_rows' => $referenceRows,
            'identity_rows' => $identityRows,
            'exact_rows' => $exactRows,
            'bank_api_read_success' => true,
            'bank_native_signature_verified' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }

    private function digest(array $parts, string $key): string
    {
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), $key);
    }
}
