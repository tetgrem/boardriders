<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodStatementReconciliationPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodStatementReconciliationPolicyTest extends TestCase
{
    private CheckoutCodStatementReconciliationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodStatementReconciliationPolicy();
    }

    private function evaluate31(
        int $claimed = 680,
        int $bank = 680,
        int $gross = 2000,
        int $fee = 1320,
        int $nova = 680,
        int $count = 1,
        bool $reviewed = true,
        bool $risk = false
    ): array {
        return $this->policy->compare(
            $claimed, $bank, $gross, $fee, $nova, $count, $reviewed, $risk
        );
    }

    public function test_statement_reconciliation31_balanced_stays_unverified(): void
    {
        $v = $this->evaluate31();
        $this->assertSame('balanced_unverified', $v['result_code']);
        $this->assertSame(0, $v['difference_minor']);
        $this->assertTrue($v['balanced_unverified']);
        $this->assertFalse($v['funds_received_verified']);
        $this->assertFalse($v['cod_paid']);
    }

    public function test_statement_reconciliation31_bank_underpayment(): void
    {
        $v = $this->evaluate31(bank: 500);
        $this->assertSame('bank_underpayment', $v['result_code']);
        $this->assertSame(-180, $v['difference_minor']);
    }

    public function test_statement_reconciliation31_bank_overpayment(): void
    {
        $v = $this->evaluate31(bank: 720);
        $this->assertSame('bank_overpayment', $v['result_code']);
        $this->assertSame(40, $v['difference_minor']);
    }

    public function test_statement_reconciliation31_novapay_net_difference(): void
    {
        $this->assertSame('novapay_difference',
            $this->evaluate31(gross: 2100, nova: 780)['result_code']);
    }

    public function test_statement_reconciliation31_fee_mismatch(): void
    {
        $this->assertSame('fee_difference',
            $this->evaluate31(fee: 1319)['result_code']);
    }

    public function test_statement_reconciliation31_unreviewed_source(): void
    {
        $this->assertSame('upstream_unreviewed',
            $this->evaluate31(reviewed: false)['result_code']);
    }

    public function test_statement_reconciliation31_missing_allocations(): void
    {
        $this->assertSame('upstream_unreviewed',
            $this->evaluate31(count: 0)['result_code']);
    }

    public function test_statement_reconciliation31_risk_overrides_both_amounts_and_review(): void
    {
        $this->assertSame('evidence_conflict',
            $this->evaluate31(bank: 500, reviewed: false, risk: true)['result_code']);
    }

    public function test_statement_reconciliation31_large_values_are_integer_only(): void
    {
        $v = $this->evaluate31(
            claimed: 100000000, bank: 99999999,
            gross: 100000000, fee: 0, nova: 100000000
        );
        $this->assertSame(-1, $v['difference_minor']);
    }

    public function test_statement_reconciliation31_invalid_inputs_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->evaluate31(fee: -1);
    }
}
