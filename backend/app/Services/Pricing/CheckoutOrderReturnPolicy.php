<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use InvalidArgumentException;

/** Prices and discounts always come from the original immutable checkout snapshot. */
final class CheckoutOrderReturnPolicy
{
    public function normalize(array $items): array
    {
        if ($items === [] || count($items) > 200 || !array_is_list($items)) {
            throw new InvalidArgumentException('Return requires 1..200 distinct variants');
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_int($item['variant_id'] ?? null)
                || !is_int($item['quantity'] ?? null)
                || $item['variant_id'] <= 0 || $item['quantity'] < 1
                || $item['quantity'] > 99 || isset($out[$item['variant_id']])) {
                throw new InvalidArgumentException('Invalid or duplicate return item');
            }
            $out[$item['variant_id']] = $item['quantity'];
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    /** @param array<int,int> $requested; @param array<int,array<int,bool>> $claimed */
    public function allocate(array $snapshot, array $requested, array $claimed): array
    {
        if (($snapshot['schema_version'] ?? null) !== 1 || !is_array($snapshot['lines'] ?? null)) {
            throw new DomainException('Invalid immutable financial snapshot');
        }
        $source = [];
        foreach ($snapshot['lines'] as $line) {
            if (!is_array($line) || !is_int($line['variant_id'] ?? null)
                || !is_int($line['quantity'] ?? null) || !is_array($line['units'] ?? null)
                || count($line['units']) !== $line['quantity'] || isset($source[$line['variant_id']])) {
                throw new DomainException('Invalid financial snapshot unit allocation');
            }
            $source[$line['variant_id']] = $line;
        }
        $rows = [];
        $totals = $this->zero();
        foreach ($requested as $variant => $quantity) {
            $line = $source[$variant] ?? null;
            if ($line === null) {
                throw new DomainException('Return variant is not in order');
            }
            $chosen = 0;
            foreach ($line['units'] as $unit) {
                if (!is_array($unit) || !is_int($unit['unit_index'] ?? null)
                    || !is_int($unit['gross_minor'] ?? null)
                    || !is_int($unit['promotion_discount_minor'] ?? null)
                    || !is_int($unit['loyalty_discount_minor'] ?? null)
                    || !is_int($unit['cash_minor'] ?? null)) {
                    throw new DomainException('Invalid financial snapshot unit');
                }
                $idx = $unit['unit_index'];
                $gross = $unit['gross_minor'];
                $promo = $unit['promotion_discount_minor'];
                $loyalty = $unit['loyalty_discount_minor'];
                $cash = $unit['cash_minor'];
                if ($idx < 1 || $idx > $line['quantity'] || $gross < 0 || $promo < 0
                    || $loyalty < 0 || $cash < 0 || $gross !== $promo + $loyalty + $cash) {
                    throw new DomainException('Corrupt per-unit monetary allocation');
                }
                if (isset($claimed[$variant][$idx])) {
                    continue;
                }
                if ($chosen === $quantity) {
                    continue;
                }
                $rows[] = [
                    'variant_id' => $variant, 'unit_index' => $idx,
                    'gross_minor' => $gross, 'promotion_minor' => $promo,
                    'loyalty_minor' => $loyalty, 'cash_minor' => $cash,
                ];
                $chosen++;
                $totals['units']++;
                $totals['gross_minor'] += $gross;
                $totals['promotion_minor'] += $promo;
                $totals['loyalty_minor'] += $loyalty;
                $totals['cash_minor'] += $cash;
            }
            if ($chosen !== $quantity) {
                throw new DomainException('Return quantity exceeds remaining units');
            }
        }
        return ['units' => $rows, 'totals' => $totals];
    }

    public function inspect(array $units, array $conditions): array
    {
        if (!array_is_list($conditions) || count($conditions) !== count($units)) {
            throw new InvalidArgumentException('Inspection must classify every requested unit');
        }
        $source = [];
        foreach ($units as $u) {
            $key = (int) $u['variant_id'] . ':' . (int) $u['unit_index'];
            $source[$key] = $u;
        }
        $seen = [];
        $items = [];
        $totals = $this->zero();
        $totals['not_received_units'] = 0;
        foreach ($conditions as $c) {
            if (!is_array($c) || !is_int($c['variant_id'] ?? null)
                || !is_int($c['unit_index'] ?? null)
                || !in_array($c['condition'] ?? null, ['resellable', 'damaged', 'not_received'], true)) {
                throw new InvalidArgumentException('Invalid inspection item');
            }
            $key = $c['variant_id'] . ':' . $c['unit_index'];
            if (!isset($source[$key]) || isset($seen[$key])) {
                throw new InvalidArgumentException('Unknown or duplicate inspection unit');
            }
            $seen[$key] = true;
            $u = $source[$key];
            $items[] = [
                'return_unit_id' => (int) $u['id'],
                'condition' => $c['condition'],
            ];
            if ($c['condition'] === 'not_received') {
                $totals['not_received_units']++;
                continue;
            }
            $totals['units']++;
            foreach (['gross_minor', 'promotion_minor', 'loyalty_minor', 'cash_minor'] as $field) {
                $totals[$field] += (int) $u[$field];
            }
        }
        usort($items, static fn (array $a, array $b): int => $a['return_unit_id'] <=> $b['return_unit_id']);
        return ['items' => $items, 'totals' => $totals];
    }

    private function zero(): array
    {
        return [
            'units' => 0, 'gross_minor' => 0, 'promotion_minor' => 0,
            'loyalty_minor' => 0, 'cash_minor' => 0,
        ];
    }
}
