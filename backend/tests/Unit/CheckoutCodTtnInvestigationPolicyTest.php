<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodTtnInvestigationPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodTtnInvestigationPolicyTest extends TestCase
{
    private CheckoutCodTtnInvestigationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        $this->policy = new CheckoutCodTtnInvestigationPolicy();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_deadline_must_be_in_the_future(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->deadline(CarbonImmutable::now('UTC'));
    }

    public function test_deadline_cannot_exceed_thirty_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->deadline(CarbonImmutable::now('UTC')->addDays(31));
    }

    public function test_deadline_allows_historical_normalization_for_retry(): void
    {
        $past = CarbonImmutable::now('UTC')->subDay();
        $this->assertSame('2026-11-07 12:00:00.000000+00:00', $this->policy->deadline($past, false));
    }

    public function test_assignment_requires_positive_assignee(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->event('assign', 'initial_assignment', 0, null);
    }

    public function test_escalation_cannot_have_assignee(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->event('escalate', 'sla_breached', 123, null);
    }

    public function test_reschedule_requires_deadline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->event('reschedule', 'carrier_response_pending', null, null);
    }

    public function test_invalid_archive_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->event('archive', 'release_safety_hold', null, null);
    }

    public function test_empty_case_awaits_assignment(): void
    {
        $state = $this->policy->project('2026-11-09T12:00:00Z', [], CarbonImmutable::now('UTC'));
        $this->assertSame('awaiting_assignment', $state['status']);
        $this->assertNull($state['assignee_id']);
        $this->assertFalse($state['ttn_submission_allowed']);
    }

    public function test_assignments_use_latest_assignee(): void
    {
        $events = [
            ['event_type' => 'assign', 'assignee_id' => 41, 'deadline_at' => null],
            ['event_type' => 'assign', 'assignee_id' => 42, 'deadline_at' => null],
        ];
        $state = $this->policy->project('2026-11-09T12:00:00Z', $events, CarbonImmutable::now('UTC'));
        $this->assertSame('in_progress', $state['status']);
        $this->assertSame(42, $state['assignee_id']);
    }

    public function test_overdue_is_calculated_without_mutation(): void
    {
        $state = $this->policy->project('2026-11-07T12:00:00Z', [], CarbonImmutable::now('UTC'));
        $this->assertTrue($state['overdue']);
    }

    public function test_reschedule_changes_effective_deadline(): void
    {
        $events = [
            ['event_type' => 'assign', 'assignee_id' => 41, 'deadline_at' => null],
            ['event_type' => 'reschedule', 'assignee_id' => null, 'deadline_at' => '2026-11-10T12:00:00Z'],
        ];
        $state = $this->policy->project('2026-11-07T12:00:00Z', $events, CarbonImmutable::now('UTC'));
        $this->assertFalse($state['overdue']);
        $this->assertSame('2026-11-10 12:00:00.000000+00:00', $state['deadline_at']);
    }

    public function test_escalation_does_not_authorize_provider(): void
    {
        $events = [
            ['event_type' => 'assign', 'assignee_id' => 41, 'deadline_at' => null],
            ['event_type' => 'escalate', 'assignee_id' => null, 'deadline_at' => null],
        ];
        $state = $this->policy->project('2026-11-09T12:00:00Z', $events, CarbonImmutable::now('UTC'));
        $this->assertSame('escalated', $state['status']);
        $this->assertFalse($state['automatic_retry_allowed']);
    }

    public function test_archiving_is_administrative_only(): void
    {
        $events = [
            ['event_type' => 'assign', 'assignee_id' => 41, 'deadline_at' => null],
            ['event_type' => 'archive', 'assignee_id' => null, 'deadline_at' => null],
        ];
        $state = $this->policy->project('2026-11-07T12:00:00Z', $events, CarbonImmutable::now('UTC'));
        $this->assertSame('administratively_archived', $state['status']);
        $this->assertFalse($state['overdue']);
        $this->assertFalse($state['reconciliation_hold_released']);
        $this->assertFalse($state['ttn_submission_allowed']);
    }

    public function test_history_after_archive_is_rejected(): void
    {
        $events = [
            ['event_type' => 'archive', 'assignee_id' => null, 'deadline_at' => null],
            ['event_type' => 'assign', 'assignee_id' => 22, 'deadline_at' => null],
        ];
        $this->expectException(InvalidArgumentException::class);
        $this->policy->project('2026-11-09T12:00:00Z', $events, CarbonImmutable::now('UTC'));
    }
}
