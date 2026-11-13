<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodCaseAlertPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodCaseAlertPolicyTest extends TestCase
{
    private function kinds(
        string $status,
        ?int $user,
        string $at
    ): array {
        $alerts = (new CheckoutCodCaseAlertPolicy())->alerts(
            $status,
            $user,
            '2026-11-13T10:00:00Z',
            '2026-11-14T10:00:00Z',
            CarbonImmutable::parse($at)
        );

        return array_column($alerts, 'kind');
    }

    public function test_cod_alert43_no_early_alert(): void
    {
        $this->assertSame(
            [],
            $this->kinds(
                'open', null, '2026-11-13T10:29:59Z'
            )
        );
    }

    public function test_cod_alert43_unassigned_after_thirty_minutes(): void
    {
        $this->assertSame(
            ['unassigned_30m'],
            $this->kinds(
                'open', null, '2026-11-13T10:30:00Z'
            )
        );
    }

    public function test_cod_alert43_claimed_case_has_no_unassigned_alert(): void
    {
        $this->assertSame(
            [],
            $this->kinds(
                'in_progress', 1200, '2026-11-13T12:00:00Z'
            )
        );
    }

    public function test_cod_alert43_due_soon_is_targeted_to_owner(): void
    {
        $a = (new CheckoutCodCaseAlertPolicy())->alerts(
            'in_progress',
            1200,
            '2026-11-13T10:00:00Z',
            '2026-11-14T10:00:00Z',
            CarbonImmutable::parse(
                '2026-11-14T09:00:00Z'
            )
        );

        $this->assertSame(
            [[
                'kind' => 'due_soon',
                'recipient_id' => 1200,
            ]],
            $a
        );
    }

    public function test_cod_alert43_overdue_starts_exactly_at_deadline(): void
    {
        $this->assertSame(
            ['overdue'],
            $this->kinds(
                'in_progress', 1200, '2026-11-14T10:00:00Z'
            )
        );
    }

    public function test_cod_alert43_four_hour_escalation(): void
    {
        $this->assertSame(
            ['overdue', 'overdue_4h'],
            $this->kinds(
                'waiting_external',
                1200,
                '2026-11-14T14:00:00Z'
            )
        );
    }

    public function test_cod_alert43_twenty_four_hour_escalation(): void
    {
        $this->assertSame(
            ['overdue', 'overdue_4h', 'overdue_24h'],
            $this->kinds(
                'in_progress',
                1200,
                '2026-11-15T10:00:00Z'
            )
        );
    }

    public function test_cod_alert43_closed_case_sends_nothing(): void
    {
        $this->assertSame(
            [],
            $this->kinds(
                'closed_internal_review',
                1200,
                '2026-11-18T10:00:00Z'
            )
        );
    }

    public function test_cod_alert43_due_soon_without_owner_targets_team(): void
    {
        $a = (new CheckoutCodCaseAlertPolicy())->alerts(
            'open',
            null,
            '2026-11-13T10:00:00Z',
            '2026-11-14T10:00:00Z',
            CarbonImmutable::parse(
                '2026-11-14T09:30:00Z'
            )
        );

        $this->assertSame(
            null,
            $a[1]['recipient_id']
        );
    }

    public function test_cod_alert43_invalid_status_rejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->kinds(
            'paid',
            1200,
            '2026-11-14T10:00:00Z'
        );
    }

    public function test_cod_alert43_invalid_assignee_rejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->kinds(
            'open',
            0,
            '2026-11-14T10:00:00Z'
        );
    }
}
