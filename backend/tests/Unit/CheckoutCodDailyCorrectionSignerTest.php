<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodDailyCorrectionSigner;
use Tests\TestCase;

final class CheckoutCodDailyCorrectionSignerTest extends TestCase
{
    private function signer(): CheckoutCodDailyCorrectionSigner
    {
        return app(CheckoutCodDailyCorrectionSigner::class);
    }

    public function test_cod_correction46_canonical_observation_ignores_map_order(): void
    {
        $s = $this->signer();
        $a = ['current' => ['b' => 2, 'a' => 1], 'delta' => []];
        $b = ['delta' => [], 'current' => ['a' => 1, 'b' => 2]];
        $this->assertSame(
            $s->observation('report', str_repeat('a', 64), $a),
            $s->observation('report', str_repeat('a', 64), $b)
        );
    }

    public function test_cod_correction46_observation_changes_with_money(): void
    {
        $s = $this->signer();
        $this->assertNotSame(
            $s->observation('report', str_repeat('a', 64), ['bank' => 680]),
            $s->observation('report', str_repeat('a', 64), ['bank' => 681])
        );
    }

    public function test_cod_correction46_observation_binds_base_report_signature(): void
    {
        $s = $this->signer();
        $this->assertNotSame(
            $s->observation('report', str_repeat('a', 64), ['bank' => 680]),
            $s->observation('report', str_repeat('b', 64), ['bank' => 680])
        );
    }

    public function test_cod_correction46_appendix_binds_author_and_reason(): void
    {
        $s = $this->signer();
        $base = $s->appendix('c', 'op', 'r', str_repeat('a', 64), 1201,
            'late_register', str_repeat('b', 64), ['value' => 1]);
        $this->assertNotSame($base, $s->appendix('c', 'op', 'r', str_repeat('a', 64), 1202,
            'late_register', str_repeat('b', 64), ['value' => 1]));
        $this->assertNotSame($base, $s->appendix('c', 'op', 'r', str_repeat('a', 64), 1201,
            'late_bank_booking', str_repeat('b', 64), ['value' => 1]));
    }

    public function test_cod_correction46_event_binds_predecessor_and_action(): void
    {
        $s = $this->signer();
        $base = str_repeat('c', 64);
        $a = $s->event('c', 'e', 'approved', 1202, 'evidence_reviewed', str_repeat('a', 64), $base);
        $b = $s->event('c', 'e', 'approved', 1202, 'evidence_reviewed', str_repeat('b', 64), $base);
        $c = $s->event('c', 'e', 'rejected', 1202, 'insufficient_evidence', str_repeat('a', 64), $base);
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}
