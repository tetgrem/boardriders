<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodTtnLifecyclePolicy;
use PHPUnit\Framework\TestCase;

final class CheckoutCodTtnLifecyclePolicyTest extends TestCase
{
    private function base(): array
    {
        return ['state' => 'awaiting_evidence', 'code' => null, 'at' => null, 'review' => false];
    }

    private function advance(array $state, string $code, string $at = '2026-11-15T12:00:00Z'): array
    {
        return (new CheckoutCodTtnLifecyclePolicy())->advance($state, 'observed', $code, $at);
    }

    private function previous(array $result): array
    {
        return ['state' => $result['state'], 'code' => $result['code'],
            'at' => $result['at'], 'review' => $result['review']];
    }

    public function test_cod_life51_label_only_is_not_shipped(): void
    {
        $this->assertSame('label_created', $this->advance($this->base(), '1')['state']);
    }

    public function test_cod_life51_transit_codes(): void
    {
        foreach (['4', '41', '5', '6', '101', '104', '111', '112'] as $code) {
            $this->assertSame('in_transit', $this->advance($this->base(), $code)['state']);
        }
    }

    public function test_cod_life51_pickup_codes(): void
    {
        foreach (['7', '8'] as $code) {
            $s = $this->advance($this->base(), $code);
            $this->assertSame('awaiting_pickup', $s['state']);
            $this->assertSame('contact_recipient', $s['action']);
        }
    }

    public function test_cod_life51_received_codes_require_financial_reconciliation(): void
    {
        foreach (['9', '10', '11'] as $code) {
            $s = $this->advance($this->base(), $code);
            $this->assertSame('received', $s['state']);
            $this->assertSame('verify_cod_collection', $s['action']);
        }
    }

    public function test_cod_life51_refusal_is_not_return_receipt(): void
    {
        foreach (['103', '108'] as $code) {
            $s = $this->advance($this->base(), $code);
            $this->assertSame('refused', $s['state']);
            $this->assertSame('follow_up_return', $s['action']);
        }
    }

    public function test_cod_life51_unclaimed_is_separate_from_refusal(): void
    {
        $this->assertSame('unclaimed', $this->advance($this->base(), '105')['state']);
    }

    public function test_cod_life51_return_requested_is_not_returned(): void
    {
        $this->assertSame('return_requested', $this->advance($this->base(), '102')['state']);
    }

    public function test_cod_life51_unknown_status_preserves_empty_state(): void
    {
        $r = $this->advance($this->base(), '999');
        $this->assertSame('ignored_unknown', $r['decision']);
        $this->assertSame('awaiting_evidence', $r['state']);
    }

    public function test_cod_life51_regression_to_transit_is_ignored(): void
    {
        $p = $this->previous($this->advance($this->base(), '7'));
        $r = $this->advance($p, '5', '2026-11-15T12:01:00Z');
        $this->assertSame('ignored_regression', $r['decision']);
        $this->assertSame('awaiting_pickup', $r['state']);
    }

    public function test_cod_life51_received_then_refusal_creates_review_conflict(): void
    {
        $p = $this->previous($this->advance($this->base(), '9'));
        $r = $this->advance($p, '103', '2026-11-15T12:01:00Z');
        $this->assertSame('conflict', $r['decision']);
        $this->assertSame('received', $r['state']);
        $this->assertTrue($r['review']);
        $this->assertSame('manual_carrier_review', $r['action']);
    }

    public function test_cod_life51_refused_then_received_creates_review_conflict(): void
    {
        $p = $this->previous($this->advance($this->base(), '103'));
        $r = $this->advance($p, '9', '2026-11-15T12:01:00Z');
        $this->assertSame('conflict', $r['decision']);
        $this->assertSame('refused', $r['state']);
        $this->assertTrue($r['review']);
    }

    public function test_cod_life51_stale_sample_cannot_overwrite_recent_status(): void
    {
        $p = $this->previous($this->advance($this->base(), '7'));
        $r = $this->advance($p, '9', '2026-11-15T11:59:59Z');
        $this->assertSame('ignored_stale', $r['decision']);
        $this->assertSame('awaiting_pickup', $r['state']);
    }

    public function test_cod_life51_provider_failure_preserves_last_good_state(): void
    {
        $p = $this->previous($this->advance($this->base(), '7'));
        $r = (new CheckoutCodTtnLifecyclePolicy())->advance(
            $p, 'provider_failure', null, '2026-11-15T12:01:00Z'
        );
        $this->assertSame('ignored_failure', $r['decision']);
        $this->assertSame('awaiting_pickup', $r['state']);
    }

    public function test_cod_life51_refusal_can_progress_to_return_requested(): void
    {
        $p = $this->previous($this->advance($this->base(), '103'));
        $r = $this->advance($p, '102', '2026-11-15T12:01:00Z');
        $this->assertSame('advanced', $r['decision']);
        $this->assertSame('return_requested', $r['state']);
    }

    public function test_cod_life51_ambiguous_reverse_waybill_needs_review(): void
    {
        $r = $this->advance($this->base(), '106');
        $this->assertSame('received_reverse_created', $r['state']);
        $this->assertTrue($r['review']);
    }

    public function test_cod_life51_same_code_is_idempotent_state(): void
    {
        $p = $this->previous($this->advance($this->base(), '7'));
        $r = $this->advance($p, '7', '2026-11-15T12:01:00Z');
        $this->assertSame('unchanged', $r['decision']);
        $this->assertSame($p['at'], $r['at']);
    }
}
