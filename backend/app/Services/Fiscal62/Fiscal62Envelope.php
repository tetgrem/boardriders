<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use DomainException;
use RuntimeException;

/**
 * Only prevalidated data reaches Checkbox.
 * Prices are original UAH kopecks.
 */
final class Fiscal62Envelope
{
    public function __construct(
        private readonly Fiscal62Profiles $profiles
    ) {}

    public function build(
        array $document,
        string $receiptId,
        ?string $relatedId
    ): array {
        if (
            !in_array(
                $document['kind'] ?? null,
                ['sale', 'refund'],
                true
            )
            || !is_array($document['payload']['items'] ?? null)
            || ($document['is_fiscal_receipt'] ?? null) !== false
        ) {
            throw new DomainException(
                'Original non-fiscal draft required'
            );
        }

        if (
            ($document['kind'] === 'refund')
            !== ($relatedId !== null)
        ) {
            throw new DomainException(
                'Refund requires the real original fiscal receipt ID'
            );
        }

        $goods = [];
        $sum = 0;

        foreach ($document['payload']['items'] as $item) {
            if (
                !is_array($item)
                || !is_int($item['variant_id'] ?? null)
                || !is_int($item['cash_minor'] ?? null)
                || $item['cash_minor'] <= 0
            ) {
                // Zero-money lines require accounting-specific treatment.
                throw new DomainException(
                    'Zero/invalid cash item cannot be auto-fiscalized'
                );
            }

            $profile = $this->profiles->read(
                $item['variant_id']
            );

            $price = $item['cash_minor'];

            if ($sum > PHP_INT_MAX - $price) {
                throw new RuntimeException(
                    'Fiscal amount overflow'
                );
            }

            $sum += $price;

            $good = [
                'good' => [
                    'code' => $profile['fiscal_code'],
                    'name' => $profile['fiscal_name'],
                    'price' => $price,
                    'tax' => [$profile['tax_code']],
                ],
                'quantity' => 1000,
            ];

            if ($document['kind'] === 'refund') {
                $good['is_return'] = true;
            }

            $goods[] = $good;
        }

        if (
            $goods === []
            || $sum !== $document['amount_minor']
        ) {
            throw new DomainException(
                'Fiscal goods must match the original monetary draft'
            );
        }

        $body = [
            'id' => $receiptId,
            'goods' => $goods,
            'payments' => [[
                'type' => 'CASHLESS',
                'value' => $sum,
                'label' => 'Безготівково',
            ]],
            'rounding' => false,
        ];

        if ($relatedId !== null) {
            $body['related_receipt_id'] = $relatedId;
        }

        return $body;
    }
}
