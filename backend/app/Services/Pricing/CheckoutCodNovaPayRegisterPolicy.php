<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Validates a normalized operator-supplied payout register, never its origin. */
final class CheckoutCodNovaPayRegisterPolicy
{
    /**
     * @param list<array<string,mixed>> $expected
     * @param list<array<string,mixed>> $submitted
     * @return array{lines:list<array<string,mixed>>,orders_count:int,gross_minor:int,fee_minor:int,bank_minor:int}
     */
    public function compare(array $expected, array $submitted): array
    {
        if (!array_is_list($expected) || !array_is_list($submitted)
            || count($expected) < 1 || count($expected) > 1000
            || count($expected) !== count($submitted)) {
            throw new InvalidArgumentException('Incomplete NovaPay register coverage');
        }

        $byOrder = [];
        $gross = $fee = $bank = 0;
        foreach ($expected as $line) {
            if (!is_array($line) || !isset($line['order_key'], $line['gross_minor'],
                    $line['fee_minor'], $line['net_minor'])
                || !is_string($line['order_key']) || !Str::isUuid($line['order_key'])
                || !is_int($line['gross_minor']) || !is_int($line['fee_minor'])
                || !is_int($line['net_minor'])
                || $line['gross_minor'] < 1 || $line['gross_minor'] > 100000000
                || $line['fee_minor'] < 0 || $line['net_minor'] < 1
                || $line['gross_minor'] !== $line['fee_minor'] + $line['net_minor']) {
                throw new InvalidArgumentException('Invalid expected COD allocation');
            }
            $key = strtolower($line['order_key']);
            if (isset($byOrder[$key])) {
                throw new InvalidArgumentException('Repeated expected COD order');
            }
            $byOrder[$key] = $line;
        }

        $refs = [];
        $shipments = [];
        $seen = [];
        $lines = [];
        foreach ($submitted as $line) {
            if (!is_array($line) || count($line) !== 6
                || array_diff(['order_key', 'shipment_number', 'external_payment_ref',
                    'gross_minor', 'fee_minor', 'net_minor'], array_keys($line)) !== []
                || !is_string($line['order_key'] ?? null) || !Str::isUuid($line['order_key'])
                || !is_string($line['shipment_number'])
                || !preg_match('/^[0-9]{14}$/D', $line['shipment_number'])
                || !is_string($line['external_payment_ref'])
                || !preg_match('/^[A-Za-z0-9_-]{6,80}$/D', $line['external_payment_ref'])
                || !is_int($line['gross_minor']) || !is_int($line['fee_minor'])
                || !is_int($line['net_minor'])) {
                throw new InvalidArgumentException('Invalid normalized NovaPay register line');
            }
            $key = strtolower($line['order_key']);
            $upstream = $byOrder[$key] ?? null;
            if ($upstream === null || isset($seen[$key])
                || isset($refs[$line['external_payment_ref']])
                || isset($shipments[$line['shipment_number']])
                || $line['gross_minor'] !== $upstream['gross_minor']
                || $line['fee_minor'] !== $upstream['fee_minor']
                || $line['net_minor'] !== $upstream['net_minor']) {
                throw new InvalidArgumentException('NovaPay register does not match COD allocation');
            }
            $seen[$key] = true;
            $refs[$line['external_payment_ref']] = true;
            $shipments[$line['shipment_number']] = true;
            $gross += $line['gross_minor'];
            $fee += $line['fee_minor'];
            $bank += $line['net_minor'];
            $line['order_key'] = $key;
            $lines[] = $line;
        }
        usort($lines, static fn (array $a, array $b): int =>
        strcmp($a['order_key'], $b['order_key']));

        return [
            'lines' => $lines,
            'orders_count' => count($lines),
            'gross_minor' => $gross,
            'fee_minor' => $fee,
            'bank_minor' => $bank,
        ];
    }
}
