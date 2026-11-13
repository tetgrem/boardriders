<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodCorrectionSlaPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodCorrectionSlaPolicyTest extends TestCase
{
    private function at(string $phase, int $minutes): ?array
    {
        $start = CarbonImmutable::parse('2026-11-13T12:00:00Z');
        return (new CheckoutCodCorrectionSlaPolicy())->evaluate(
            $phase, $start, $start->addMinutes($minutes)
        );
    }

    public function test_cod_sla48_review_does_not_warn_early(): void
    {
        $this->assertNull($this->at('review', 179));
    }

    public function test_cod_sla48_review_warns_exactly_one_hour_before_due(): void
    {
        $r = $this->at('review', 180);
        $this->assertSame('due_soon', $r['stage']);
        $this->assertSame('finance_review', $r['target_role']);
        $this->assertSame('warning', $r['severity']);
    }

    public function test_cod_sla48_review_is_overdue_exactly_at_deadline(): void
    {
        $r = $this->at('review', 240);
        $this->assertSame('overdue', $r['stage']);
        $this->assertSame('high', $r['severity']);
    }

    public function test_cod_sla48_management_escalation_after_four_hours(): void
    {
        $r = $this->at('review', 480);
        $this->assertSame('overdue_4h', $r['stage']);
        $this->assertSame('finance_management', $r['target_role']);
    }

    public function test_cod_sla48_critical_stage_after_twenty_four_hours(): void
    {
        $this->assertSame('overdue_24h', $this->at('review', 1680)['stage']);
    }

    public function test_cod_sla48_publication_has_its_own_shorter_deadline(): void
    {
        $this->assertNull($this->at('publication', 59));
        $r = $this->at('publication', 60);
        $this->assertSame('due_soon', $r['stage']);
        $this->assertSame('finance_publication', $r['target_role']);
        $this->assertSame('overdue', $this->at('publication', 120)['stage']);
    }

    public function test_cod_sla48_rejection_raises_immediate_followup(): void
    {
        $r = $this->at('rejection', 0);
        $this->assertSame('rejected_action', $r['stage']);
        $this->assertSame('finance_management', $r['target_role']);
        $this->assertSame('overdue', $this->at('rejection', 1440)['stage']);
    }

    public function test_cod_sla48_custom_timing_is_supported(): void
    {
        $p = new CheckoutCodCorrectionSlaPolicy(30, 20, 40, 10, 60, 120);
        $start = CarbonImmutable::parse('2026-11-13T12:00:00Z');
        $this->assertSame('due_soon', $p->evaluate('review', $start, $start->addMinutes(20))['stage']);
    }

    public function test_cod_sla48_rejects_invalid_phase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->at('customer_paid', 120);
    }

    public function test_cod_sla48_rejects_inverted_escalation_thresholds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CheckoutCodCorrectionSlaPolicy(240, 120, 1440, 60, 1440, 240);
    }
}
