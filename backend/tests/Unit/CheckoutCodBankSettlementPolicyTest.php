<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodBankSettlementPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodBankSettlementPolicyTest extends TestCase
{
    private CheckoutCodBankSettlementPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodBankSettlementPolicy();
    }

    public function test_bank_settlement_equal_claims_are_not_verified_money(): void
    {
        $result = $this->policy->compare(680, 680, 'consistent_with_estimate', 'UAH');
        $this->assertSame('amounts_match_unverified', $result['match_result']);
        $this->assertTrue($result['amounts_equal']);
        $this->assertFalse($result['funds_received_verified']);
        $this->assertFalse($result['cod_paid']);
    }

    public function test_bank_settlement_positive_difference_is_in_kopiykas(): void
    {
        $result = $this->policy->compare(680, 700, 'consistent_with_estimate', 'UAH');
        $this->assertSame('bank_net_mismatch', $result['match_result']);
        $this->assertSame(20, $result['difference_minor']);
    }

    public function test_bank_settlement_negative_difference_is_in_kopiykas(): void
    {
        $this->assertSame(-80, $this->policy->compare(
            680, 600, 'consistent_with_estimate', 'UAH'
        )['difference_minor']);
    }

    public function test_bank_settlement_source_discrepancy_takes_priority(): void
    {
        $this->assertSame('source_claim_discrepancy', $this->policy->compare(
            680, 680, 'gross_mismatch', 'UAH'
        )['match_result']);
    }

    public function test_bank_settlement_missing_fee_estimate_cannot_confirm_match(): void
    {
        $this->assertSame('source_claim_discrepancy', $this->policy->compare(
            680, 680, 'fee_not_estimated', 'UAH'
        )['match_result']);
    }

    public function test_bank_settlement_rejects_foreign_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->compare(680, 680, 'consistent_with_estimate', 'USD');
    }

    public function test_bank_settlement_rejects_unbounded_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->compare(680, 100000001, 'consistent_with_estimate', 'UAH');
    }

    public function test_bank_settlement_matching_review_is_still_unverified(): void
    {
        $this->policy->assertReview(
            'amounts_match_unverified', 'retain_external_confirmation',
            'bank_and_novapay_unverified'
        );
        $this->addToAssertionCount(1);
    }

    public function test_bank_settlement_discrepancy_cannot_be_called_confirmed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->assertReview(
            'bank_net_mismatch', 'retain_external_confirmation',
            'bank_and_novapay_unverified'
        );
    }
}
