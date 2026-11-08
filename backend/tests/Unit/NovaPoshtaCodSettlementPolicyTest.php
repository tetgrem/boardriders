<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaCodSettlementPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaCodSettlementPolicyTest extends TestCase
{
    private NovaPoshtaCodSettlementPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new NovaPoshtaCodSettlementPolicy();
    }

    private function check(
        int $cod = 2000,
        int $declared = 3000,
        string $channel = 'cash_branch',
        ?int $fee = 1200,
    ): array {
        return $this->policy->evaluate($cod, $declared, $channel, $fee);
    }

    public function test_cash_limit_boundary_is_recorded_as_local_only(): void
    {
        $result = $this->check(cod: 39999900);
        $this->assertSame('within_published_limit', $result['local_result']);
        $this->assertSame(39999900, $result['payout_limit_minor']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
    }

    public function test_cash_above_limit_is_blocked(): void
    {
        $this->assertSame('blocked_payout_limit',
            $this->check(cod: 39999901)['local_result']);
    }

    public function test_novapay_card_limit_boundary(): void
    {
        $this->assertSame('within_published_limit',
            $this->check(cod: 10000000, channel: 'novapay_card')['local_result']);
    }

    public function test_novapay_card_above_limit_is_blocked(): void
    {
        $this->assertSame('blocked_payout_limit',
            $this->check(cod: 10000001, channel: 'novapay_card')['local_result']);
    }

    public function test_other_card_limit_boundary(): void
    {
        $this->assertSame('within_published_limit',
            $this->check(cod: 2999900, channel: 'other_ua_card')['local_result']);
    }

    public function test_other_card_above_limit_is_blocked(): void
    {
        $this->assertSame('blocked_payout_limit',
            $this->check(cod: 2999901, channel: 'other_ua_card')['local_result']);
    }

    public function test_declared_value_is_not_automatically_equal_to_cod(): void
    {
        $result = $this->check(cod: 2000, declared: 5000);
        $this->assertSame(2000, $result['cod_amount_minor']);
        $this->assertSame(5000, $result['declared_value_minor']);
        $this->assertTrue($result['declared_value_differs_from_cod']);
    }

    public function test_missing_estimated_fee_stays_unknown(): void
    {
        $result = $this->check(fee: null);
        $this->assertSame('missing_fee', $result['local_result']);
        $this->assertNull($result['estimated_cod_fee_minor']);
    }

    public function test_zero_fee_is_not_missing(): void
    {
        $result = $this->check(fee: 0);
        $this->assertSame('within_published_limit', $result['local_result']);
        $this->assertSame(0, $result['estimated_cod_fee_minor']);
    }

    public function test_invalid_payout_channel_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->check(channel: 'foreign_card');
    }

    public function test_zero_cod_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->check(cod: 0);
    }

    public function test_zero_declared_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->check(declared: 0);
    }

    public function test_negative_fee_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->check(fee: -1);
    }

    public function test_local_pass_never_authorizes_dispatch_or_settlement(): void
    {
        $result = $this->check();
        $this->assertFalse($result['ready_for_dispatch']);
        $this->assertFalse($result['fee_finalized']);
        $this->assertFalse($result['declared_value_carrier_verified']);
        $this->assertFalse($result['payout_channel_contract_verified']);
    }
}
