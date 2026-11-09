<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodTtnDispatchPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodTtnDispatchPolicyTest extends TestCase
{
    private CheckoutCodTtnDispatchPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodTtnDispatchPolicy();
    }

    public function test_local_stale_is_blocked(): void
    {
        $report = $this->policy->evaluate(false, [], false);

        $this->assertSame(
            'local_context_stale',
            $report['status']
        );
        $this->assertFalse($report['ttn_submission_allowed']);
    }

    public function test_local_current_without_review_is_blocked(): void
    {
        $report = $this->policy->evaluate(true, [], false);

        $this->assertSame(
            'awaiting_independent_review',
            $report['status']
        );
        $this->assertFalse($report['ready_for_dispatch']);
    }

    public function test_reviewed_local_plan_is_not_issuer_authorization(): void
    {
        $report = $this->policy->evaluate(true, [], true);

        $this->assertSame(
            'awaiting_trusted_carrier_authorization',
            $report['status']
        );
        $this->assertFalse($report['issuer_contract_verified']);
        $this->assertFalse($report['carrier_cod_acceptance_verified']);
    }

    public function test_reconciliation_hold_dominates_review(): void
    {
        $report = $this->policy->evaluate(
            true,
            ['reconciliation_required'],
            true
        );

        $this->assertSame(
            'reconciliation_required',
            $report['status']
        );
        $this->assertFalse($report['manual_retry_allowed']);
    }

    public function test_cancelled_plan_is_blocked(): void
    {
        $report = $this->policy->evaluate(
            true,
            ['cancel_before_submission'],
            true
        );

        $this->assertSame('cancelled', $report['status']);
        $this->assertFalse($report['ttn_submission_allowed']);
    }

    public function test_safety_hold_is_blocked(): void
    {
        $report = $this->policy->evaluate(
            true,
            ['safety_hold'],
            true
        );

        $this->assertSame('safety_hold', $report['status']);
    }

    public function test_unknown_event_is_not_ignored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(
            true,
            ['release_safety_hold'],
            true
        );
    }

    public function test_no_policy_state_ever_enables_live_transport(): void
    {
        foreach ([true, false] as $current) {
            foreach ([true, false] as $reviewed) {
                foreach ([
                             [],
                             ['safety_hold'],
                             ['reconciliation_required'],
                             ['cancel_before_submission'],
                         ] as $events) {
                    $report = $this->policy->evaluate(
                        $current,
                        $events,
                        $reviewed
                    );

                    $this->assertFalse($report['ttn_submission_allowed']);
                    $this->assertFalse($report['automatic_retry_allowed']);
                    $this->assertFalse($report['provider_call_made']);
                }
            }
        }
    }
}
