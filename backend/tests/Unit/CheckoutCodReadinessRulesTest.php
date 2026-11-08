<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodReadinessRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodReadinessRulesTest extends TestCase
{
    private CheckoutCodReadinessRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new CheckoutCodReadinessRules();
    }

    /** @return array<string, bool> */
    private function completed(): array
    {
        return array_fill_keys(
            CheckoutCodReadinessRules::INTERNAL_CHECKS,
            true
        );
    }

    public function test_empty_input_fails_closed(): void
    {
        $report = $this->rules->evaluate([]);

        $this->assertFalse($report['local_checks_complete']);
        $this->assertSame(
            'awaiting_local_completion',
            $report['phase']
        );
        $this->assertCount(14, $report['blockers']);
    }

    public function test_complete_local_checks_still_cannot_approve_shipping(): void
    {
        $report = $this->rules->evaluate($this->completed());

        $this->assertTrue($report['local_checks_complete']);
        $this->assertSame(
            'awaiting_external_evidence',
            $report['phase']
        );
        $this->assertSame('blocked', $report['status']);
        $this->assertFalse($report['ready_for_dispatch']);
        $this->assertCount(4, $report['blockers']);
    }

    public function test_single_missing_check_has_a_precise_blocker(): void
    {
        $checks = $this->completed();
        $checks['stock_active'] = false;

        $report = $this->rules->evaluate($checks);

        $this->assertSame(
            'stock_active',
            $report['blockers'][0]
        );
        $this->assertFalse($report['local_checks_complete']);
    }

    public function test_unknown_check_cannot_be_injected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rules->evaluate(['contract_approved' => true]);
    }

    public function test_non_boolean_check_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rules->evaluate(['order_pending' => 1]);
    }

    public function test_external_cod_agreement_is_always_unverified(): void
    {
        $report = $this->rules->evaluate($this->completed());

        $this->assertFalse(
            $report['carrier_cod_agreement_verified']
        );
        $this->assertContains(
            'cod_merchant_agreement_not_verified',
            $report['blockers']
        );
    }

    public function test_payout_agreement_is_always_unverified(): void
    {
        $report = $this->rules->evaluate($this->completed());

        $this->assertFalse($report['payout_contract_verified']);
        $this->assertContains(
            'payout_contract_not_verified',
            $report['blockers']
        );
    }

    public function test_provider_acceptance_is_always_unverified(): void
    {
        $report = $this->rules->evaluate($this->completed());

        $this->assertFalse(
            $report['carrier_cod_acceptance_verified']
        );
        $this->assertFalse($report['cod_eligibility_confirmed']);
    }

    public function test_missing_checks_preserve_deterministic_order(): void
    {
        $report = $this->rules->evaluate([
            'stock_active' => false,
            'order_pending' => true,
        ]);

        $this->assertSame(
            'checkout_active',
            $report['blockers'][0]
        );
        $this->assertSame(
            'stock_active',
            $report['blockers'][8]
        );
    }

    public function test_ruleset_and_flags_are_stable(): void
    {
        $first = $this->rules->evaluate($this->completed());
        $second = $this->rules->evaluate($this->completed());

        $this->assertSame($first, $second);
        $this->assertSame(
            CheckoutCodReadinessRules::VERSION,
            $first['ruleset']
        );
    }
}
