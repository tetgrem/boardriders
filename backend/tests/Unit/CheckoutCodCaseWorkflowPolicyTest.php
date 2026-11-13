<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodCaseWorkflowPolicy;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodCaseWorkflowPolicyTest extends TestCase
{
    private function policy(): CheckoutCodCaseWorkflowPolicy
    {
        return new CheckoutCodCaseWorkflowPolicy();
    }

    public function test_cod_case42_critical_sla_is_four_hours(): void
    {
        $this->assertSame(
            4,
            $this->policy()->slaHours('critical')
        );
    }

    public function test_cod_case42_normal_sla_is_twenty_four_hours(): void
    {
        $this->assertSame(
            24,
            $this->policy()->slaHours('normal')
        );
    }

    public function test_cod_case42_low_sla_is_seventy_two_hours(): void
    {
        $this->assertSame(
            72,
            $this->policy()->slaHours('low')
        );
    }

    public function test_cod_case42_unknown_sla_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy()->slaHours('urgent');
    }

    public function test_cod_case42_open_case_can_be_claimed(): void
    {
        $this->assertSame(
            'in_progress',
            $this->policy()->next(
                'open', 'claim', false, false, false, false
            )
        );
    }

    public function test_cod_case42_only_owner_can_request_evidence(): void
    {
        $this->expectException(DomainException::class);

        $this->policy()->next(
            'in_progress',
            'request_evidence',
            false,
            false,
            false,
            false
        );
    }

    public function test_cod_case42_waiting_case_can_be_resumed(): void
    {
        $this->assertSame(
            'in_progress',
            $this->policy()->next(
                'waiting_external',
                'resume',
                true,
                false,
                false,
                false
            )
        );
    }

    public function test_cod_case42_owner_can_release_waiting_case(): void
    {
        $this->assertSame(
            'open',
            $this->policy()->next(
                'waiting_external',
                'release',
                true,
                false,
                false,
                false
            )
        );
    }

    public function test_cod_case42_close_requires_both_clean_and_independent_review(): void
    {
        $this->expectException(DomainException::class);

        $this->policy()->next(
            'in_progress',
            'close',
            true,
            false,
            true,
            false
        );
    }

    public function test_cod_case42_clean_independent_comparison_can_close_internally(): void
    {
        $this->assertSame(
            'closed_internal_review',
            $this->policy()->next(
                'in_progress',
                'close',
                true,
                false,
                true,
                true
            )
        );
    }

    public function test_cod_case42_stale_case_cannot_be_claimed(): void
    {
        $this->expectException(DomainException::class);

        $this->policy()->next(
            'open',
            'claim',
            false,
            true,
            false,
            false
        );
    }

    public function test_cod_case42_refresh_reopens_after_new_evidence(): void
    {
        $this->assertSame(
            'open',
            $this->policy()->next(
                'closed_internal_review',
                'refresh',
                false,
                true,
                false,
                false
            )
        );
    }

    public function test_cod_case42_refresh_without_change_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        $this->policy()->next(
            'in_progress',
            'refresh',
            true,
            false,
            false,
            false
        );
    }

    public function test_cod_case42_invalid_action_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy()->assertReason(
            'close',
            'bank_difference'
        );
    }

    public function test_cod_case42_unknown_action_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy()->next(
            'open',
            'delete',
            false,
            false,
            false,
            false
        );
    }
}
