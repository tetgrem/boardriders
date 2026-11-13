<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodNovaPayRegisterPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodNovaPayRegisterPolicyTest extends TestCase
{
    private const ORDER_A = '11111111-1111-4111-8111-111111111111';
    private const ORDER_B = '22222222-2222-4222-8222-222222222222';

    private function expected(): array
    {
        return [
            ['order_key' => self::ORDER_A, 'gross_minor' => 2000,
                'fee_minor' => 1320, 'net_minor' => 680],
            ['order_key' => self::ORDER_B, 'gross_minor' => 3000,
                'fee_minor' => 800, 'net_minor' => 2200],
        ];
    }

    private function submitted(): array
    {
        return [
            ['order_key' => self::ORDER_A, 'shipment_number' => '20450000000001',
                'external_payment_ref' => 'PAYMENT_A39', 'gross_minor' => 2000,
                'fee_minor' => 1320, 'net_minor' => 680],
            ['order_key' => self::ORDER_B, 'shipment_number' => '20450000000002',
                'external_payment_ref' => 'PAYMENT_B39', 'gross_minor' => 3000,
                'fee_minor' => 800, 'net_minor' => 2200],
        ];
    }

    public function test_novapay_register39_matches_exact_multiorder_totals(): void
    {
        $r = (new CheckoutCodNovaPayRegisterPolicy())->compare(
            $this->expected(), $this->submitted()
        );
        $this->assertSame(2, $r['orders_count']);
        $this->assertSame(5000, $r['gross_minor']);
        $this->assertSame(2120, $r['fee_minor']);
        $this->assertSame(2880, $r['bank_minor']);
    }

    public function test_novapay_register39_input_order_does_not_change_result(): void
    {
        $policy = new CheckoutCodNovaPayRegisterPolicy();
        $this->assertSame(
            $policy->compare($this->expected(), $this->submitted()),
            $policy->compare($this->expected(), array_reverse($this->submitted()))
        );
    }

    public function test_novapay_register39_missing_order_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare(
            $this->expected(), [$this->submitted()[0]]
        );
    }

    public function test_novapay_register39_gross_mismatch_is_rejected(): void
    {
        $rows = $this->submitted();
        $rows[0]['gross_minor']++;
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare($this->expected(), $rows);
    }

    public function test_novapay_register39_fee_mismatch_is_rejected(): void
    {
        $rows = $this->submitted();
        $rows[0]['fee_minor']++;
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare($this->expected(), $rows);
    }

    public function test_novapay_register39_duplicate_payment_reference_is_rejected(): void
    {
        $rows = $this->submitted();
        $rows[1]['external_payment_ref'] = $rows[0]['external_payment_ref'];
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare($this->expected(), $rows);
    }

    public function test_novapay_register39_duplicate_shipment_is_rejected(): void
    {
        $rows = $this->submitted();
        $rows[1]['shipment_number'] = $rows[0]['shipment_number'];
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare($this->expected(), $rows);
    }

    public function test_novapay_register39_unknown_order_is_rejected(): void
    {
        $rows = $this->submitted();
        $rows[1]['order_key'] = '33333333-3333-4333-8333-333333333333';
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare($this->expected(), $rows);
    }

    public function test_novapay_register39_invalid_payment_reference_is_rejected(): void
    {
        $rows = $this->submitted();
        $rows[0]['external_payment_ref'] = 'x';
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare($this->expected(), $rows);
    }

    public function test_novapay_register39_invalid_shipment_number_is_rejected(): void
    {
        $rows = $this->submitted();
        $rows[0]['shipment_number'] = '123';
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodNovaPayRegisterPolicy())->compare($this->expected(), $rows);
    }
}
