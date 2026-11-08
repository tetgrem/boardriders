<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaCodContractEvidencePolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaCodContractEvidencePolicyTest extends TestCase
{
    private NovaPoshtaCodContractEvidencePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new NovaPoshtaCodContractEvidencePolicy();
    }

    private function active(): array
    {
        return [
            'valid_from' => '2026-01-01T00:00:00+00:00',
            'valid_until' => '2028-01-01T00:00:00+00:00',
        ];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-08T12:00:00+00:00');
    }

    public function test_profile_code_is_normalized(): void
    {
        $this->assertSame('ua_primary', $this->policy->profileCode(' UA_PRIMARY '));
    }

    public function test_invalid_profile_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->profileCode('bad profile');
    }

    public function test_transport_document_requires_nova_poshta_issuer(): void
    {
        $this->policy->capability('transport_agreement', 'nova_poshta');
        $this->addToAssertionCount(1);
    }

    public function test_cod_document_requires_novapay_issuer(): void
    {
        $this->policy->capability('cod_agreement', 'novapay');
        $this->addToAssertionCount(1);
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->capability('payout_agreement', 'nova_poshta');
    }

    public function test_invalid_sha_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->hash(str_repeat('A', 64));
    }

    public function test_indefinite_document_is_allowed(): void
    {
        $period = $this->policy->period($this->now(), null);
        $this->assertNull($period[1]);
    }

    public function test_invalid_expiry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->period($this->now(), $this->now());
    }

    public function test_missing_evidence_is_missing(): void
    {
        $this->assertSame('missing', $this->policy->state(null, null, false, $this->now()));
    }

    public function test_unreviewed_evidence_remains_pending(): void
    {
        $this->assertSame('pending_review', $this->policy->state($this->active(), null, false, $this->now()));
    }

    public function test_rejected_review_is_never_accepted(): void
    {
        $this->assertSame('rejected', $this->policy->state($this->active(), 'rejected', false, $this->now()));
    }

    public function test_revocation_overrides_accepted_review(): void
    {
        $this->assertSame('revoked', $this->policy->state($this->active(), 'accepted', true, $this->now()));
    }

    public function test_future_start_prevents_current_acceptance(): void
    {
        $record = $this->active();
        $record['valid_from'] = '2027-01-01T00:00:00+00:00';
        $this->assertSame('not_yet_valid', $this->policy->state($record, 'accepted', false, $this->now()));
    }

    public function test_expired_evidence_is_not_current(): void
    {
        $record = $this->active();
        $record['valid_until'] = '2026-02-01T00:00:00+00:00';
        $this->assertSame('expired', $this->policy->state($record, 'accepted', false, $this->now()));
    }

    public function test_accepted_active_evidence_is_only_internally_accepted(): void
    {
        $this->assertSame('internally_accepted', $this->policy->state($this->active(), 'accepted', false, $this->now()));
    }

    public function test_review_reason_must_match_decision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->decisionReason('accepted', 'reference_mismatch');
    }

    public function test_unknown_revocation_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->revocationReason('just_because');
    }
}
