<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** A carrier list lookup is neither a complete search nor proof of order ownership. */
final class NovaPoshtaTtnReconciliationPolicy
{
    public const RESULTS = [
        'number_observed_once',
        'number_absent_in_page',
        'duplicate_number',
        'response_inconclusive',
    ];

    public function number(string $number): string
    {
        if (!preg_match('/^[0-9]{14}$/D', $number)) {
            throw new InvalidArgumentException('Expected a 14-digit carrier waybill number');
        }
        return $number;
    }

    /** @param array<int, mixed> $rows @return array{result:string,rows_examined:int} */
    public function classify(array $rows, string $number): array
    {
        $this->number($number);
        $count = count($rows);
        if ($count > 100) {
            throw new InvalidArgumentException('Oversized carrier document response');
        }

        $matches = 0;
        $malformed = false;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['IntDocNumber'])
                || !is_string($row['IntDocNumber'])
                || !preg_match('/^[0-9]{14}$/D', $row['IntDocNumber'])
            ) {
                $malformed = true;
                continue;
            }
            if (hash_equals($row['IntDocNumber'], $number)) {
                $matches++;
            }
        }

        $result = match (true) {
            $matches > 1 => 'duplicate_number',
            $malformed || $count === 100 => 'response_inconclusive',
            $matches === 1 => 'number_observed_once',
            default => 'number_absent_in_page',
        };
        return ['result' => $result, 'rows_examined' => $count];
    }

    public function review(string $decision, string $reason, string $scanResult): void
    {
        if (!in_array($scanResult, self::RESULTS, true)) {
            throw new InvalidArgumentException('Unknown reconciliation scan result');
        }
        $valid = match ($decision) {
            'escalate_to_carrier' => (
                ($reason === 'candidate_seen' && $scanResult === 'number_observed_once')
                || ($reason === 'duplicate_number' && $scanResult === 'duplicate_number')
            ),
            'keep_reconciliation_hold' => in_array($reason, [
                'insufficient_api_evidence', 'order_link_unverified',
            ], true),
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException('Invalid reconciliation decision/reason');
        }
    }
}
