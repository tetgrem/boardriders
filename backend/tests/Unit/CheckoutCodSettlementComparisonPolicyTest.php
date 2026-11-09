<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodSettlementComparisonPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodSettlementComparisonPolicyTest extends TestCase
{
    private CheckoutCodSettlementComparisonPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodSettlementComparisonPolicy();
    }

    public function test_matching_statement_remains_unverified(): void
    {
        $result = $this->policy->compare(
            2000, 1320, 2000, 1320, 680, 'UAH'
        );

        $this->assertSame(
            'consistent_with_estimate',
            $result['comparison_result']
        );

        $this->assertTrue($result['amounts_match_estimate']);
        $this->assertFalse($result['funds_received_verified']);
        $this->assertFalse($result['cod_paid']);
    }

    public function test_gross_mismatch_has_exact_signed_difference(): void
    {
        $result = $this->policy->compare(
            2000, 1320, 2500, 1320, 1180, 'UAH'
        );

        $this->assertSame(
            'gross_mismatch',
            $result['comparison_result']
        );

        $this->assertSame(500, $result['gross_difference_minor']);
    }

    public function test_net_arithmetic_is_exact_in_minor_units(): void
    {
        $result = $this->policy->compare(
            2000, 1320, 2000, 1320, 681, 'UAH'
        );

        $this->assertSame(
            'net_arithmetic_mismatch',
            $result['comparison_result']
        );
    }

    public function test_fee_difference_is_not_assumed_to_be_fraud(): void
    {
        $result = $this->policy->compare(
            2000, 1320, 2000, 1200, 800, 'UAH'
        );

        $this->assertSame(
            'fee_differs_from_estimate',
            $result['comparison_result']
        );

        $this->assertSame(
            -120,
            $result['fee_difference_minor']
        );
    }

    public function test_missing_fee_estimate_is_preserved(): void
    {
        $result = $this->policy->compare(
            2000, null, 2000, 100, 1900, 'UAH'
        );

        $this->assertSame(
            'fee_not_estimated',
            $result['comparison_result']
        );

        $this->assertNull($result['fee_difference_minor']);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->compare(
            2000, 100, 2000, -1, 2001, 'UAH'
        );
    }

    public function test_foreign_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->compare(
            2000, 100, 2000, 100, 1900, 'USD'
        );
    }

    public function test_approval_cannot_be_claimed_through_review(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->reviewDecision(
            'consistent_with_estimate',
            'paid',
            'external_confirmation_required'
        );
    }
}
