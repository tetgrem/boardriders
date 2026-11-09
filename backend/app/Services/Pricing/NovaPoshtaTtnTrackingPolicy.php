<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Parse only the carrier's exact numeric status; never echo free-form PII. */
final class NovaPoshtaTtnTrackingPolicy
{
    /**
     * @param list<mixed> $rows
     * @return array{outcome:string,status_code:?string}
     */
    public function classify(array $rows, string $number): array
    {
        if (!preg_match('/^[0-9]{14}$/D', $number)) {
            throw new InvalidArgumentException('Invalid 14-digit TTN');
        }

        $unknown = [
            'outcome' => 'inconclusive',
            'status_code' => null,
        ];

        if (count($rows) !== 1 || !is_array($rows[0])) {
            return $unknown;
        }

        $row = $rows[0];
        $reported = $row['Number'] ?? null;
        $code = $row['StatusCode'] ?? null;

        if (
            !is_string($reported)
            || !hash_equals($number, $reported)
            || (!is_string($code) && !is_int($code))
        ) {
            return $unknown;
        }

        $code = (string) $code;

        if (!preg_match('/^[0-9]{1,3}$/D', $code)) {
            return $unknown;
        }

        return [
            'outcome' => 'observed',
            'status_code' => $code,
        ];
    }
}
