<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use DomainException;
use RuntimeException;

/**
 * Internal monetary draft.
 * NOT a fiscal receipt or tax-compliant provider payload.
 */
final class Fiscal61PayloadBuilder
{
    public function sale(array $snapshot, string $orderKey): array
    {
        if (
            ($snapshot['schema_version'] ?? null) !== 1
            || ($snapshot['currency_code'] ?? null) !== 'UAH'
            || !is_array($snapshot['lines'] ?? null)
        ) {
            throw new DomainException('Invalid checkout snapshot');
        }

        $items = [];
        $total = 0;

        foreach ($snapshot['lines'] as $line) {
            if (
                !is_array($line)
                || !is_int($line['variant_id'] ?? null)
                || !is_int($line['quantity'] ?? null)
                || $line['quantity'] <= 0
                || !is_array($line['units'] ?? null)
                || count($line['units']) !== $line['quantity']
            ) {
                throw new DomainException(
                    'Invalid sale line quantities'
                );
            }

            foreach ($line['units'] as $unit) {
                $item = $this->unit(
                    $line['variant_id'],
                    $unit
                );

                $items[] = $item;
                $total = $this->add(
                    $total,
                    $item['cash_minor']
                );
            }
        }

        if (
            $items === []
            || $total !== ($snapshot['goods_payable_minor'] ?? null)
            || $total <= 0
        ) {
            throw new RuntimeException(
                'Sale draft does not match paid checkout'
            );
        }

        return [
            'schema_version' => 1,
            'kind' => 'sale',
            'order_key' => $orderKey,
            'currency_code' => 'UAH',
            'amount_minor' => $total,
            'items' => $items,
            'tax_mapping' => 'not_configured',
            'is_fiscal_receipt' => false,
        ];
    }

    public function refund(
        array $salePayload,
        array $returnUnits,
        array $inspectionItems,
        int $expectedMinor
    ): array {
        if (
            ($salePayload['kind'] ?? null) !== 'sale'
            || !is_array($salePayload['items'] ?? null)
            || !is_array($inspectionItems)
            || !array_is_list($inspectionItems)
            || count($returnUnits) !== count($inspectionItems)
        ) {
            throw new DomainException(
                'Invalid sale/return draft relationship'
            );
        }

        $original = [];

        foreach ($salePayload['items'] as $item) {
            $key = $item['variant_id'] . ':' . $item['unit_index'];

            if (isset($original[$key])) {
                throw new RuntimeException('Duplicate paid sale unit');
            }

            $original[$key] = $item;
        }

        $returnMap = [];

        foreach ($returnUnits as $unit) {
            $id = (int) $unit->id;
            $key = (int) $unit->variant_id
                . ':' . (int) $unit->unit_index;

            $originalItem = $original[$key] ?? null;

            if (
                $originalItem === null
                || (int) $originalItem['cash_minor']
                !== (int) $unit->cash_minor
                || isset($returnMap[$id])
            ) {
                throw new DomainException(
                    'RMA monetary unit differs from original sale'
                );
            }

            $returnMap[$id] = $originalItem;
        }

        $items = [];
        $seen = [];
        $sum = 0;

        foreach ($inspectionItems as $classification) {
            $id = $classification['return_unit_id'] ?? null;
            $condition = $classification['condition'] ?? null;

            if (
                !is_int($id)
                || isset($seen[$id])
                || !isset($returnMap[$id])
                || !in_array(
                    $condition,
                    ['resellable', 'damaged', 'not_received'],
                    true
                )
            ) {
                throw new DomainException(
                    'Invalid inspected return unit'
                );
            }

            $seen[$id] = true;

            if ($condition === 'not_received') {
                continue;
            }

            $item = $returnMap[$id];
            $item['return_unit_id'] = $id;
            $item['condition'] = $condition;

            $items[] = $item;
            $sum = $this->add($sum, $item['cash_minor']);
        }

        if (
            count($seen) !== count($returnUnits)
            || $items === []
            || $sum <= 0
            || $sum !== $expectedMinor
        ) {
            throw new DomainException(
                'RMA refund draft does not reconcile with Mono journal'
            );
        }

        usort(
            $items,
            static fn ($a, $b) =>
                $a['return_unit_id'] <=> $b['return_unit_id']
        );

        return [
            'schema_version' => 1,
            'kind' => 'refund',
            'currency_code' => 'UAH',
            'amount_minor' => $sum,
            'items' => $items,
            'requires_original_fiscal_number' => true,
            'tax_mapping' => 'not_configured',
            'is_fiscal_receipt' => false,
        ];
    }

    private function unit(int $variantId, array $unit): array
    {
        foreach ([
                     'unit_index',
                     'gross_minor',
                     'promotion_discount_minor',
                     'loyalty_discount_minor',
                     'cash_minor',
                 ] as $key) {
            if (
                !is_int($unit[$key] ?? null)
                || $unit[$key] < 0
            ) {
                throw new DomainException(
                    'Invalid checkout unit money'
                );
            }
        }

        if (
            $unit['unit_index'] < 1
            || $unit['gross_minor']
            !== $unit['promotion_discount_minor']
            + $unit['loyalty_discount_minor']
            + $unit['cash_minor']
        ) {
            throw new DomainException(
                'Per-unit allocation does not balance'
            );
        }

        return [
            'variant_id' => $variantId,
            'unit_index' => $unit['unit_index'],
            'quantity' => 1,
            'gross_minor' => $unit['gross_minor'],
            'promotion_discount_minor' =>
                $unit['promotion_discount_minor'],
            'loyalty_discount_minor' =>
                $unit['loyalty_discount_minor'],
            'cash_minor' => $unit['cash_minor'],
        ];
    }

    private function add(int $a, int $b): int
    {
        if ($b < 0 || $a > PHP_INT_MAX - $b) {
            throw new RuntimeException('Fiscal monetary overflow');
        }

        return $a + $b;
    }
}
