<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodDailyMonitorPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodDailyMonitorPolicyTest extends TestCase
{
    private function decide(bool $hasChanges, ?string $matching = null, bool $otherPublished = false): array
    {
        return (new CheckoutCodDailyMonitorPolicy())->decide($hasChanges, $matching, $otherPublished);
    }

    public function test_cod_monitor47_clean_day_needs_no_attention(): void
    {
        $this->assertSame('clean', $this->decide(false)['status']);
        $this->assertFalse($this->decide(false)['requires_attention']);
    }

    public function test_cod_monitor47_uncovered_financial_change_is_critical(): void
    {
        $r = $this->decide(true);
        $this->assertSame('correction_required', $r['status']);
        $this->assertSame('critical', $r['severity']);
        $this->assertSame('propose', $r['next_action']);
    }

    public function test_cod_monitor47_proposed_correction_waits_for_review(): void
    {
        $r = $this->decide(true, 'proposed');
        $this->assertSame('awaiting_review', $r['status']);
        $this->assertSame('warning', $r['severity']);
    }

    public function test_cod_monitor47_approved_correction_waits_for_publication(): void
    {
        $this->assertSame('publish', $this->decide(true, 'approved')['next_action']);
    }

    public function test_cod_monitor47_published_matching_correction_covers_evidence(): void
    {
        $r = $this->decide(true, 'published');
        $this->assertSame('covered', $r['status']);
        $this->assertFalse($r['requires_attention']);
    }

    public function test_cod_monitor47_rejection_does_not_resolve_financial_risk(): void
    {
        $r = $this->decide(true, 'rejected');
        $this->assertSame('rejected_requires_action', $r['status']);
        $this->assertSame('investigate', $r['next_action']);
    }

    public function test_cod_monitor47_new_evidence_after_publication_is_critical(): void
    {
        $r = $this->decide(true, null, true);
        $this->assertSame('changed_after_publication', $r['status']);
        $this->assertSame('propose_updated', $r['next_action']);
    }

    public function test_cod_monitor47_invalid_correction_state_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->decide(true, 'paid');
    }
}
