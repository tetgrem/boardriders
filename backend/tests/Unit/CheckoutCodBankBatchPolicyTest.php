<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodBankBatchPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodBankBatchPolicyTest extends TestCase
{
    private CheckoutCodBankBatchPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodBankBatchPolicy();
    }

    public function test_bank_batch_empty_is_not_balanced(): void
    {
        $r = $this->policy->evaluate(1360, 0, 0);

        $this->assertSame(1360, $r['remaining_minor']);
        $this->assertSame(
            'allocation_partial_unverified',
            $r['decision']
        );
    }

    public function test_bank_batch_partial_allocation_is_unverified(): void
    {
        $r = $this->policy->evaluate(1360, 680, 1);

        $this->assertSame(
            'allocation_partial_unverified',
            $r['decision']
        );
        $this->assertSame('unallocated_balance', $r['reason_code']);
        $this->assertFalse($r['funds_received_verified']);
    }

    public function test_bank_batch_fully_allocated_is_not_paid(): void
    {
        $r = $this->policy->evaluate(1360, 1360, 2);

        $this->assertSame(
            'allocation_balanced_unverified',
            $r['decision']
        );
        $this->assertSame(0, $r['remaining_minor']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_bank_batch_overallocation_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(1000, 1001, 2);
    }

    public function test_bank_batch_negative_allocation_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(1000, -1, 1);
    }

    public function test_bank_batch_nonzero_amount_requires_rows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(1000, 1, 0);
    }

    public function test_bank_batch_positive_rows_require_allocations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(1000, 0, 2);
    }

    public function test_bank_batch_invalid_net_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(0, 0, 0);
    }

    public function test_bank_batch_amounts_remain_integer_minor_units(): void
    {
        $r = $this->policy->evaluate(1001, 500, 1);

        $this->assertSame(501, $r['remaining_minor']);
        $this->assertIsInt($r['remaining_minor']);
    }
}
