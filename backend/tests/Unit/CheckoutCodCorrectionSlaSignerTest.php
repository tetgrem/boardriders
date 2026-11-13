<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodCorrectionSlaSigner;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class CheckoutCodCorrectionSlaSignerTest extends TestCase
{
    public function test_cod_sla48_alert_signature_binds_stage_target_and_deadline(): void
    {
        $s = app(CheckoutCodCorrectionSlaSigner::class);
        $h = str_repeat('a', 64);
        $due = '2026-11-13T17:00:00.000000Z';
        $a = $s->alert('correction', $h, 1, 'review', 'due_soon', 'warning', 'finance_review', $due);
        $this->assertNotSame($a, $s->alert('correction', $h, 1, 'review', 'overdue', 'high', 'finance_review', $due));
        $this->assertNotSame($a, $s->alert('correction', $h, 1, 'review', 'due_soon', 'warning', 'finance_management', $due));
        $this->assertNotSame($a, $s->alert('correction', $h, 1, 'review', 'due_soon', 'warning', 'finance_review', '2026-11-13T18:00:00.000000Z'));
    }

    public function test_cod_sla48_receipt_signature_binds_actor_and_operation(): void
    {
        $s = app(CheckoutCodCorrectionSlaSigner::class);
        $h = str_repeat('b', 64);
        $a = $s->receipt($h, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1204);
        $this->assertNotSame($a, $s->receipt($h, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1206));
        $this->assertNotSame($a, $s->receipt($h, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 1204));
    }

    public function test_cod_sla48_utc_timestamp_is_canonical(): void
    {
        $s = app(CheckoutCodCorrectionSlaSigner::class);
        $this->assertSame(
            $s->stamp('2026-11-13T15:00:00Z'),
            $s->stamp(CarbonImmutable::parse('2026-11-13T17:00:00+02:00'))
        );
    }
}
