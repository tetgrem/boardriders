<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodPostingPreflightPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodPostingPreflightPolicyTest extends TestCase
{
    private CheckoutCodPostingPreflightPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CheckoutCodPostingPreflightPolicy();
    }

    public function test_posting_preflight32_balanced_remains_unpaid(): void
    {
        $v = $this->policy->evaluate(
            2000, 2000, 1320, 680, true, true
        );

        $this->assertSame(
            'comparison_complete_unverified',
            $v['result_code']
        );
        $this->assertTrue($v['can_prepare_review']);
        $this->assertFalse($v['payment_posting_allowed']);
        $this->assertFalse($v['funds_received_verified']);
        $this->assertFalse($v['cod_paid']);
    }

    public function test_posting_preflight32_gross_mismatch(): void
    {
        $v = $this->policy->evaluate(
            2000, 2100, 1320, 780, true, true
        );

        $this->assertSame(
            'cod_gross_mismatch',
            $v['result_code']
        );
        $this->assertSame(100, $v['difference_minor']);
    }

    public function test_posting_preflight32_fee_exceeds_gross(): void
    {
        $v = $this->policy->evaluate(
            2000, 2000, 2001, 0, true, true
        );

        $this->assertSame(
            'cod_fee_exceeds_gross',
            $v['result_code']
        );
    }

    public function test_posting_preflight32_net_mismatch(): void
    {
        $v = $this->policy->evaluate(
            2000, 2000, 1320, 679, true, true
        );

        $this->assertSame(
            'cod_net_mismatch',
            $v['result_code']
        );
    }

    public function test_posting_preflight32_review_required(): void
    {
        $v = $this->policy->evaluate(
            2000, 2000, 1320, 680, false, true
        );

        $this->assertSame(
            'reconciliation_not_current',
            $v['result_code']
        );
        $this->assertFalse($v['can_prepare_review']);
    }

    public function test_posting_preflight32_pending_order_required(): void
    {
        $v = $this->policy->evaluate(
            2000, 2000, 1320, 680, true, false
        );

        $this->assertSame(
            'order_not_pending',
            $v['result_code']
        );
        $this->assertFalse($v['can_prepare_review']);
    }

    public function test_posting_preflight32_invalid_amounts_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(
            2000, 2000, -1, 2001, true, true
        );
    }
}
