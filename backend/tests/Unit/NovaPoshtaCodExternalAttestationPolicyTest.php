<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaCodExternalAttestationPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaCodExternalAttestationPolicyTest extends TestCase
{
    private NovaPoshtaCodExternalAttestationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new NovaPoshtaCodExternalAttestationPolicy();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-08T12:00:00Z');
    }

    private function claim(?string $end = '2027-01-01T00:00:00Z'): array
    {
        return [
            'issued_at' => '2026-11-01T00:00:00Z',
            'valid_until' => $end,
        ];
    }

    public function test_signed_document_source_is_supported(): void
    {
        $this->assertSame('signed_document', $this->policy->sourceKind('signed_document'));
    }

    public function test_issuer_portal_export_source_is_supported(): void
    {
        $this->assertSame('issuer_portal_export', $this->policy->sourceKind('issuer_portal_export'));
    }

    public function test_unknown_source_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->sourceKind('self_reported');
    }

    public function test_sha256_requires_lowercase_hex(): void
    {
        $this->assertSame(str_repeat('a', 64), $this->policy->sha256(str_repeat('a', 64)));
    }

    public function test_uppercase_sha256_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->sha256(str_repeat('A', 64));
    }

    public function test_period_allows_no_end_date(): void
    {
        $this->assertNull($this->policy->period($this->now(), null)[1]);
    }

    public function test_period_rejects_equal_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->period($this->now(), $this->now());
    }

    public function test_acceptance_requires_source_checked_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->reviewReason('accepted', 'other_issue');
    }

    public function test_rejection_accepts_reference_mismatch_reason(): void
    {
        $this->policy->reviewReason('rejected', 'reference_mismatch');
        $this->addToAssertionCount(1);
    }

    public function test_invalid_revocation_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->revocationReason('changed_mind');
    }

    public function test_noncurrent_contract_overrides_accepted_claim(): void
    {
        $this->assertSame('contract_not_current', $this->policy->state(
            $this->claim(), 'accepted', false, false, $this->now()
        ));
    }

    public function test_missing_claim_is_not_accepted(): void
    {
        $this->assertSame('missing', $this->policy->state(
            null, null, false, true, $this->now()
        ));
    }

    public function test_pending_review_is_not_accepted(): void
    {
        $this->assertSame('pending_review', $this->policy->state(
            $this->claim(), null, false, true, $this->now()
        ));
    }

    public function test_rejected_claim_is_not_accepted(): void
    {
        $this->assertSame('rejected', $this->policy->state(
            $this->claim(), 'rejected', false, true, $this->now()
        ));
    }

    public function test_revocation_overrides_acceptance(): void
    {
        $this->assertSame('revoked', $this->policy->state(
            $this->claim(), 'accepted', true, true, $this->now()
        ));
    }

    public function test_expiry_is_exclusive(): void
    {
        $this->assertSame('expired', $this->policy->state(
            $this->claim('2026-11-08T12:00:00Z'), 'accepted', false, true, $this->now()
        ));
    }

    public function test_indefinite_accepted_claim_is_only_internally_attested(): void
    {
        $this->assertSame('internally_attested', $this->policy->state(
            $this->claim(null), 'accepted', false, true, $this->now()
        ));
    }
}
