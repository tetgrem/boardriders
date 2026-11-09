<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodFulfillmentStagePolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodFulfillmentStagePolicyTest extends TestCase
{
    private CheckoutCodFulfillmentStagePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodFulfillmentStagePolicy();
    }

    public function test_missing_pack_is_blocked(): void
    {
        $this->assertSame(
            'pack_source_not_current',
            $this->policy->evaluate(false, false, null, false)['status']
        );
    }

    public function test_accepted_pack_waits_for_staging(): void
    {
        $this->assertSame(
            'awaiting_internal_staging',
            $this->policy->evaluate(true, false, null, false)['status']
        );
    }

    public function test_staging_requires_independent_review(): void
    {
        $this->assertSame(
            'awaiting_independent_staging_review',
            $this->policy->evaluate(true, true, null, false)['status']
        );
    }

    public function test_reviewed_staging_is_still_not_dispatched(): void
    {
        $state = $this->policy->evaluate(
            true,
            true,
            'accepted',
            false
        );

        $this->assertSame(
            'internal_staging_reviewed',
            $state['status']
        );

        $this->assertTrue($state['internal_staging_reviewed']);
        $this->assertFalse($state['carrier_handoff_allowed']);
        $this->assertFalse($state['inventory_shipped']);
    }

    public function test_hold_after_approval_takes_priority(): void
    {
        $state = $this->policy->evaluate(
            true,
            true,
            'accepted',
            true
        );

        $this->assertSame(
            'internal_handoff_hold',
            $state['status']
        );

        $this->assertFalse($state['internal_staging_reviewed']);
    }

    public function test_rejection_is_terminal_in_status(): void
    {
        $this->assertSame(
            'staging_rejected',
            $this->policy->evaluate(true, true, 'rejected', false)['status']
        );
    }

    public function test_stale_source_overrides_past_review(): void
    {
        $this->assertSame(
            'pack_source_not_current',
            $this->policy->evaluate(false, true, 'accepted', false)['status']
        );
    }

    public function test_invalid_review_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(true, true, 'shipped', false);
    }

    public function test_review_without_staging_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(true, false, 'accepted', false);
    }
}
