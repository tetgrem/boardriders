<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal print text, never a carrier label, barcode or TTN.
 * Whitelisted fields only.
 */
final class CheckoutCodInternalPickSlipFormatter
{
    /** @param array<string,mixed> $payload */
    public function render(array $payload): string
    {
        $key = $payload['order_key'] ?? null;
        $type = $payload['container_type'] ?? null;
        $items = $payload['items'] ?? null;
        $units = $payload['total_units'] ?? null;
        $gross = $payload['gross_grams'] ?? null;
        $length = $payload['length_mm'] ?? null;
        $width = $payload['width_mm'] ?? null;
        $height = $payload['height_mm'] ?? null;

        if (
            !is_string($key)
            || !Str::isUuid($key)
            || !in_array(
                $type,
                ['mailer', 'box', 'reinforced_box'],
                true
            )
            || !is_int($units)
            || $units < 1
            || !is_int($gross)
            || $gross < 1
            || !is_int($length)
            || $length < 1
            || !is_int($width)
            || $width < 1
            || !is_int($height)
            || $height < 1
            || !is_array($items)
            || $items === []
            || count($items) > 200
        ) {
            throw new InvalidArgumentException(
                'Invalid internal slip source'
            );
        }

        $lines = [
            'BOARDRIDERS | INTERNAL PICK SLIP',
            'NOT A CARRIER LABEL. NOT A TTN. DO NOT HAND TO CARRIER.',
            'Order UUID: ' . strtolower($key),
            'Container: ' . $type,
            'Expected gross: ' . $gross . ' g',
            'Outer dimensions: '
            . $length . ' x ' . $width . ' x ' . $height . ' mm',
            'VARIANT ID | UNITS',
        ];

        $sum = 0;

        foreach ($items as $item) {
            if (
                !is_array($item)
                || !is_int($item['variant_id'] ?? null)
                || $item['variant_id'] <= 0
                || !is_int($item['quantity'] ?? null)
                || $item['quantity'] <= 0
            ) {
                throw new InvalidArgumentException(
                    'Invalid internal slip line'
                );
            }

            $sum += $item['quantity'];

            $lines[] = $item['variant_id']
                . ' | '
                . $item['quantity'];
        }

        if ($sum !== $units) {
            throw new InvalidArgumentException(
                'Pack unit count mismatch'
            );
        }

        $lines[] = 'TOTAL UNITS: ' . $units;
        $lines[] =
            'Carrier label must be obtained separately after actual TTN issuance.';

        return implode("\n", $lines) . "\n";
    }
}
