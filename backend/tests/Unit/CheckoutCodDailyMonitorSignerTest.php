<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodDailyMonitorSigner;
use Tests\TestCase;

final class CheckoutCodDailyMonitorSignerTest extends TestCase
{
    private function signer(): CheckoutCodDailyMonitorSigner
    {
        return app(CheckoutCodDailyMonitorSigner::class);
    }

    public function test_cod_monitor47_signal_binds_status_and_evidence(): void
    {
        $s = $this->signer();
        $a = $s->signal('r', str_repeat('a', 64), str_repeat('b', 64),
            'awaiting_review', 'warning', 'review');

        $this->assertNotSame($a, $s->signal('r', str_repeat('a', 64), str_repeat('b', 64),
            'awaiting_publication', 'warning', 'publish'));

        $this->assertNotSame($a, $s->signal('r', str_repeat('a', 64), str_repeat('c', 64),
            'awaiting_review', 'warning', 'review'));
    }

    public function test_cod_monitor47_signal_binds_original_report_signature(): void
    {
        $s = $this->signer();
        $this->assertNotSame(
            $s->signal('r', str_repeat('a', 64), str_repeat('b', 64), 'clean', 'none', 'none'),
            $s->signal('r', str_repeat('c', 64), str_repeat('b', 64), 'clean', 'none', 'none')
        );
    }

    public function test_cod_monitor47_acknowledgement_binds_actor_and_operation(): void
    {
        $s = $this->signer();
        $signal = str_repeat('a', 64);
        $a = $s->receipt($signal, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1204);

        $this->assertNotSame(
            $a,
            $s->receipt($signal, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1203)
        );
        $this->assertNotSame(
            $a,
            $s->receipt($signal, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 1204)
        );
    }
}
