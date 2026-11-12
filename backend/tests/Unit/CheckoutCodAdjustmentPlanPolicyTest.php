<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodAdjustmentPlanPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodAdjustmentPlanPolicyTest extends TestCase
{
    private CheckoutCodAdjustmentPlanPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodAdjustmentPlanPolicy();
    }

    public function test_adjustment_plan35_partial_return_only_estimates_exposure(): void
    {
        $r = $this->policy->project('return_case_followup', 2000, 1320, 680, 680, 680, 1000);
        $this->assertSame('customer_refund_claim_unverified', $r['result_code']);
        $this->assertSame(1000, $r['proposed_customer_refund_minor']);
        $this->assertSame(0, $r['assumed_fee_recovery_minor']);
        $this->assertFalse($r['customer_refund_executed']);
        $this->assertFalse($r['financial_ledger_changed']);
    }

    public function test_adjustment_plan35_full_return_claim_allowed(): void
    {
        $r = $this->policy->project('return_case_followup', 2000, 1320, 680, 680, 680, 2000);
        $this->assertSame(2000, $r['proposed_customer_refund_minor']);
    }

    public function test_adjustment_plan35_net_correction_signed_delta(): void
    {
        $r = $this->policy->project('amount_correction', 2000, 1320, 680, 680, 600, 0);
        $this->assertSame('net_amount_correction_unverified', $r['result_code']);
        $this->assertSame(-80, $r['source_net_difference_minor']);
        $this->assertSame(0, $r['proposed_customer_refund_minor']);
    }

    public function test_adjustment_plan35_reported_reversal_is_not_customer_refund(): void
    {
        $r = $this->policy->project('reported_reversal', 2000, 1320, 680, 680, 0, 0);
        $this->assertSame(-680, $r['source_net_difference_minor']);
        $this->assertSame(0, $r['proposed_customer_refund_minor']);
        $this->assertFalse($r['payment_posting_allowed']);
    }

    public function test_adjustment_plan35_zero_return_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->project('return_case_followup', 2000, 1320, 680, 680, 680, 0);
    }

    public function test_adjustment_plan35_over_refund_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->project('return_case_followup', 2000, 1320, 680, 680, 680, 2001);
    }

    public function test_adjustment_plan35_fee_mismatch_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->project('return_case_followup', 2000, 1321, 680, 680, 680, 100);
    }

    public function test_adjustment_plan35_correction_cannot_hide_refund(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->project('amount_correction', 2000, 1320, 680, 680, 600, 100);
    }

    public function test_adjustment_plan35_reversal_requires_zero_recommendation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->project('reported_reversal', 2000, 1320, 680, 680, 100, 0);
    }

    public function test_adjustment_plan35_unsupported_batch_reallocation_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->project('batch_reallocation', 2000, 1320, 680, 680, 680, 0);
    }
}
