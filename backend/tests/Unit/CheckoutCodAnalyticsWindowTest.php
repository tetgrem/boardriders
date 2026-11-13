<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodAnalyticsWindow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodAnalyticsWindowTest extends TestCase
{
    public function test_cod_analytics44_default_uses_thirty_utc_days(): void
    {
        $w = CheckoutCodAnalyticsWindow::make(null, null,
            CarbonImmutable::parse('2026-11-13T12:30:00+02:00'));
        $this->assertSame('2026-10-15', $w->from);
        $this->assertSame('2026-11-13', $w->to);
        $this->assertSame(30, $w->days);
        $this->assertSame('2026-11-14', $w->endExclusive->toDateString());
    }

    public function test_cod_analytics44_single_day_uses_exclusive_next_midnight(): void
    {
        $w = CheckoutCodAnalyticsWindow::make('2026-11-13', '2026-11-13');
        $this->assertSame(1, $w->days);
        $this->assertSame('2026-11-14', $w->endExclusive->toDateString());
    }

    public function test_cod_analytics44_range_includes_ninety_days(): void
    {
        $w = CheckoutCodAnalyticsWindow::make('2026-09-01', '2026-11-29');
        $this->assertSame(90, $w->days);
    }

    public function test_cod_analytics44_rejects_more_than_ninety_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckoutCodAnalyticsWindow::make('2026-09-01', '2026-11-30');
    }

    public function test_cod_analytics44_rejects_invalid_calendar_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckoutCodAnalyticsWindow::make('2026-02-31', '2026-03-01');
    }

    public function test_cod_analytics44_rejects_reversed_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckoutCodAnalyticsWindow::make('2026-11-14', '2026-11-13');
    }
}
