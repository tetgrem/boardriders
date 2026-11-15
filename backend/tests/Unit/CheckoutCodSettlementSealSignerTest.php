<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodSettlementSealSigner;
use Tests\TestCase;

final class CheckoutCodSettlementSealSignerTest extends TestCase
{
    public function test_cod_seal50_canonical_map_order_is_stable(): void
    {
        $s = app(CheckoutCodSettlementSealSigner::class);
        $a = ['lines' => [['net_minor' => 680, 'gross_minor' => 2000]], 'totals' => ['gross' => 2000]];
        $b = ['totals' => ['gross' => 2000], 'lines' => [['gross_minor' => 2000, 'net_minor' => 680]]];
        $this->assertSame($s->json($a), $s->json($b));
        $this->assertSame(
            $s->digest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 5001, $a),
            $s->digest('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 5001, $b)
        );
    }

    public function test_cod_seal50_signer_detects_changed_money(): void
    {
        $s = app(CheckoutCodSettlementSealSigner::class);
        $this->assertNotSame(
            $s->digest('seal', 'op', 5001, ['net_minor' => 680]),
            $s->digest('seal', 'op', 5001, ['net_minor' => 681])
        );
    }

    public function test_cod_seal50_signer_binds_actor(): void
    {
        $s = app(CheckoutCodSettlementSealSigner::class);
        $this->assertNotSame(
            $s->digest('seal', 'op', 5001, ['net_minor' => 680]),
            $s->digest('seal', 'op', 5002, ['net_minor' => 680])
        );
    }

    public function test_cod_seal50_signer_binds_uuid(): void
    {
        $s = app(CheckoutCodSettlementSealSigner::class);
        $this->assertNotSame(
            $s->digest('seal-a', 'op', 5001, ['net_minor' => 680]),
            $s->digest('seal-b', 'op', 5001, ['net_minor' => 680])
        );
    }

    public function test_cod_seal50_signer_binds_ordered_line_allocation(): void
    {
        $s = app(CheckoutCodSettlementSealSigner::class);
        $this->assertNotSame(
            $s->digest('seal', 'op', 5001, ['lines' => [['order_id' => 1], ['order_id' => 2]]]),
            $s->digest('seal', 'op', 5001, ['lines' => [['order_id' => 2], ['order_id' => 1]]])
        );
    }
}
