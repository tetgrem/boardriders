<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodBankCoveragePolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodBankCoveragePolicyTest extends TestCase
{
    private CheckoutCodBankCoveragePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodBankCoveragePolicy();
    }

    private function project33(
        int $bank = 680,
        int $claimed = 680,
        int $gross = 2000,
        int $fee = 1320,
        int $allocated = 680,
        int $expected = 1,
        int $ready = 1,
        bool $current = true,
        bool $risk = false
    ): array {
        return $this->policy->evaluate(
            $bank, $claimed, $gross, $fee, $allocated,
            $expected, $ready, $current, $risk
        );
    }

    public function test_bank_coverage33_one_order_balanced_unverified(): void
    {
        $v = $this->project33();
        $this->assertSame('coverage_balanced_unverified', $v['result_code']);
        $this->assertSame(0, $v['difference_minor']);
        $this->assertTrue($v['can_compile']);
        $this->assertFalse($v['funds_received_verified']);
        $this->assertFalse($v['payment_posting_allowed']);
    }

    public function test_bank_coverage33_two_order_batch_balanced(): void
    {
        $v = $this->project33(1360, 1360, 4000, 2640, 1360, 2, 2);
        $this->assertTrue($v['can_compile']);
        $this->assertSame(2, $v['expected_orders_count']);
    }

    public function test_bank_coverage33_missing_one_order_blocks(): void
    {
        $this->assertSame('preflight_coverage_incomplete',
            $this->project33(1360, 1360, 4000, 2640, 1360, 2, 1)['result_code']);
    }

    public function test_bank_coverage33_shortfall(): void
    {
        $v = $this->project33(680, 680, 1900, 1320, 580);
        $this->assertSame('bank_allocation_shortfall', $v['result_code']);
        $this->assertSame(100, $v['difference_minor']);
    }

    public function test_bank_coverage33_allocation_overflow(): void
    {
        $this->assertSame('bank_allocation_overflow',
            $this->project33(680, 680, 2100, 1320, 780)['result_code']);
    }

    public function test_bank_coverage33_fee_discrepancy(): void
    {
        $this->assertSame('gross_fee_net_discrepancy',
            $this->project33(fee: 1319)['result_code']);
    }

    public function test_bank_coverage33_bank_claim_mismatch(): void
    {
        $this->assertSame('bank_credit_amount_mismatch',
            $this->project33(claimed: 650)['result_code']);
    }

    public function test_bank_coverage33_stale_source_precedes_arithmetic(): void
    {
        $this->assertSame('source_reconciliation_stale',
            $this->project33(current: false)['result_code']);
    }

    public function test_bank_coverage33_risk_has_priority(): void
    {
        $this->assertSame('financial_risk_hold',
            $this->project33(current: false, risk: true)['result_code']);
    }

    public function test_bank_coverage33_invalid_amounts_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->project33(ready: 2);
    }
}
