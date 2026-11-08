<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Pure strict comparison of *observed* carrier fields to an immutable checkout draft. */
final class NovaPoshtaTtnDocumentComparisonPolicy
{
    public const FIELDS = [
        'Ref', 'IntDocNumber', 'Sender', 'Recipient', 'CitySender',
        'CityRecipient', 'RecipientAddress', 'ServiceType', 'Weight', 'Cost',
    ];

    /**
     * $expected contains only refs, a 14-digit TTN, grams and integer minor units.
     * No human names or phone numbers are ever returned.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $expected
     * @return array{summary:string,checks:array<string,string>}
     */
    public function compare(array $document, array $expected): array
    {
        $checks = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $document) || $document[$field] === null) {
                $checks[$field] = 'missing';
                continue;
            }
            $actual = $this->normalize($field, $document[$field]);
            $checks[$field] = $actual === null ? 'invalid'
                : ($actual === $expected[$field] ? 'match' : 'mismatch');
        }

        $summary = in_array('mismatch', $checks, true) ? 'snapshot_mismatch'
            : (in_array('missing', $checks, true) || in_array('invalid', $checks, true)
                ? 'inconclusive_fields' : 'snapshot_consistent');

        return ['summary' => $summary, 'checks' => $checks];
    }

    /** @return array{summary:string,checks:array<string,string>} */
    public function inconclusive(string $summary): array
    {
        if (!in_array($summary, ['inconclusive_list', 'inconclusive_document'], true)) {
            throw new InvalidArgumentException('Unsupported inconclusive comparison result');
        }
        return ['summary' => $summary,
            'checks' => array_fill_keys(self::FIELDS, 'missing')];
    }

    private function normalize(string $field, mixed $value): string|int|null
    {
        if (in_array($field, ['Ref', 'Sender', 'Recipient', 'CitySender',
            'CityRecipient', 'RecipientAddress'], true)) {
            return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
        }
        if ($field === 'IntDocNumber') {
            return is_string($value) && preg_match('/^[0-9]{14}$/D', $value)
                ? $value : null;
        }
        if ($field === 'ServiceType') {
            return is_string($value) && in_array($value,
                ['WarehouseWarehouse', 'WarehousePostomat'], true) ? $value : null;
        }
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $decimal = (string) $value;
        $places = $field === 'Weight' ? 3 : 2;
        if (!preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,'.$places.'})?$/D', $decimal)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $scale = $field === 'Weight' ? 1000 : 100;
        return ((int) $whole * $scale) + (int) str_pad($fraction, $places, '0');
    }
}
