<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodTtnSubmissionSafetyPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodTtnSubmissionSafetyPolicyTest extends TestCase
{
    private CheckoutCodTtnSubmissionSafetyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodTtnSubmissionSafetyPolicy();
    }

    public function test_safety_hold_reason_is_supported(): void
    {
        $this->policy->validate('safety_hold', 'manual_review');
        $this->addToAssertionCount(1);
    }

    public function test_cancellation_reason_is_supported(): void
    {
        $this->policy->validate('cancel_before_submission', 'customer_cancelled');
        $this->addToAssertionCount(1);
    }

    public function test_reconciliation_reason_is_supported(): void
    {
        $this->policy->validate('reconciliation_required', 'reported_timeout');
        $this->addToAssertionCount(1);
    }

    public function test_unsupported_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->validate('provider_approved', 'manual_review');
    }

    public function test_mismatched_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->validate('safety_hold', 'reported_timeout');
    }

    public function test_prepared_context_remains_unsubmitted(): void
    {
        $state = $this->policy->evaluate(true, []);
        $this->assertSame('prepared_not_authorized', $state['status']);
        $this->assertFalse($state['ttn_submission_allowed']);
        $this->assertFalse($state['ready_for_dispatch']);
        $this->assertFalse($state['provider_call_made_by_this_module']);
    }

    public function test_stale_context_fails_closed(): void
    {
        $state = $this->policy->evaluate(false, []);
        $this->assertSame('context_stale', $state['status']);
        $this->assertContains('context_stale', $state['blockers']);
    }

    public function test_hold_is_a_blocker(): void
    {
        $state = $this->policy->evaluate(true, ['safety_hold']);
        $this->assertSame('safety_hold', $state['status']);
        $this->assertFalse($state['automatic_retry_allowed']);
    }

    public function test_reported_uncertainty_has_priority_over_hold(): void
    {
        $state = $this->policy->evaluate(true, [
            'safety_hold', 'reconciliation_required',
        ]);
        $this->assertSame('reconciliation_required', $state['status']);
        $this->assertTrue($state['has_reconciliation_hold']);
    }

    public function test_ambiguity_takes_priority_over_even_an_inconsistent_cancellation_record(): void
    {
        $state = $this->policy->evaluate(true, [
            'reconciliation_required', 'cancel_before_submission',
        ]);
        $this->assertSame('reconciliation_required', $state['status']);
        $this->assertFalse($state['ready_for_dispatch']);
    }

    public function test_unknown_event_in_history_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->evaluate(true, ['provider_success']);
    }

    public function test_timeout_is_never_an_automatic_retry_signal(): void
    {
        $result = $this->policy->classifyPossibleProviderFailure('timeout');
        $this->assertSame('outcome_unknown', $result['classification']);
        $this->assertTrue($result['manual_reconciliation_required']);
        $this->assertFalse($result['automatic_retry_allowed']);
    }

    public function test_http_error_cannot_prove_remote_failure(): void
    {
        $result = $this->policy->classifyPossibleProviderFailure('http_5xx');
        $this->assertNull($result['carrier_waybill_created']);
        $this->assertFalse($result['submission_attempt_proven']);
    }

    public function test_unconfirmed_4xx_is_not_assumed_side_effect_free(): void
    {
        $result = $this->policy->classifyPossibleProviderFailure('unconfirmed_http_4xx');
        $this->assertSame('outcome_unknown', $result['classification']);
    }

    public function test_unknown_failure_class_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->classifyPossibleProviderFailure('success');
    }
}
