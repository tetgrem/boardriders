<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaCodContractEvidenceService;
use App\Services\Pricing\NovaPoshtaCodExternalAttestationService;
use App\Services\Pricing\NovaPoshtaCodExternalAttestationStatusService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class NovaPoshtaCodExternalAttestationTest extends TestCase
{
    use RefreshDatabase;

    private NovaPoshtaCodContractEvidenceService $contracts;
    private NovaPoshtaCodExternalAttestationService $attestations;
    private NovaPoshtaCodExternalAttestationStatusService $status;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new RuntimeException('Requires PostgreSQL boardriders_test');
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC'));
        $this->contracts = app(NovaPoshtaCodContractEvidenceService::class);
        $this->attestations = app(NovaPoshtaCodExternalAttestationService::class);
        $this->status = app(NovaPoshtaCodExternalAttestationStatusService::class);
        Gate::define(NovaPoshtaCodContractEvidenceService::SUBMIT_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 101);
        Gate::define(NovaPoshtaCodContractEvidenceService::REVIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 202);
        Gate::define(NovaPoshtaCodExternalAttestationService::SUBMIT_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 303);
        Gate::define(NovaPoshtaCodExternalAttestationService::REVIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 404);
        Gate::define(NovaPoshtaCodExternalAttestationService::REVOKE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 505);
        $this->actor(101);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function actor(int $id): void
    {
        Auth::setUser(new GenericUser(['id' => $id]));
    }

    private function contract(
        string $capability = 'transport_agreement',
        int $revision = 1,
        string $profile = 'ua_primary',
        bool $accept = true,
        ?CarbonImmutable $until = null,
    ): array {
        $this->actor(101);
        $row = $this->contracts->submit(
            (string) Str::uuid(), $profile, $capability, $revision,
            $capability === 'transport_agreement' ? 'nova_poshta' : 'novapay',
            str_repeat('a', 64), str_repeat('b', 64),
            CarbonImmutable::now('UTC')->subDay(),
            $until ?? CarbonImmutable::now('UTC')->addYear(),
        );
        if ($accept) {
            $this->actor(202);
            $this->contracts->review((string) Str::uuid(), $row['evidence_key'],
                'accepted', 'documents_reviewed');
        }
        return $row;
    }

    private function claim(
        array $evidence, ?string $key = null,
        string $reference = '', ?CarbonImmutable $until = null,
        string $kind = 'signed_document',
    ): array {
        $this->actor(303);
        return $this->attestations->submit(
            $key ?? (string) Str::uuid(), $evidence['evidence_key'],
            $kind, str_repeat('c', 64),
            $reference ?: str_repeat('b', 64),
            CarbonImmutable::now('UTC')->subHour(),
            $until ?? CarbonImmutable::now('UTC')->addMonth(),
        );
    }

    private function review(
        array $claim, ?string $key = null,
        string $decision = 'accepted', string $reason = 'source_checked',
    ): array {
        $this->actor(404);
        return $this->attestations->review(
            $key ?? (string) Str::uuid(), $claim['claim_key'], $decision, $reason
        );
    }

    private function revoke(array $claim, ?string $key = null): array
    {
        $this->actor(505);
        return $this->attestations->revoke(
            $key ?? (string) Str::uuid(), $claim['claim_key'], 'security_hold'
        );
    }

    private function accepted(string $capability = 'transport_agreement'): array
    {
        $doc = $this->contract($capability);
        $claim = $this->claim($doc);
        $this->review($claim);
        return $claim;
    }

    public function test_claim_fingerprint_is_not_issuer_proof(): void
    {
        $claim = $this->claim($this->contract());
        $this->assertSame(303, $claim['submitted_by']);
        $this->assertFalse($claim['issuer_independently_verified']);
        $this->assertFalse($claim['ttn_submission_allowed']);
        $this->assertDatabaseCount('np_cod_external_attestation_claims', 1);
    }

    public function test_same_claim_uuid_is_idempotent(): void
    {
        $doc = $this->contract();
        $key = (string) Str::uuid();
        $this->assertSame($this->claim($doc, $key), $this->claim($doc, $key));
        $this->assertDatabaseCount('np_cod_external_attestation_claims', 1);
    }

    public function test_same_claim_uuid_cannot_change_reference(): void
    {
        $doc = $this->contract();
        $key = (string) Str::uuid();
        $this->claim($doc, $key);
        $this->expectException(DomainException::class);
        $this->claim($doc, $key, str_repeat('d', 64));
    }

    public function test_new_uuid_cannot_replace_existing_claim_for_revision(): void
    {
        $doc = $this->contract();
        $this->claim($doc);
        $this->expectException(DomainException::class);
        $this->claim($doc);
    }

    public function test_unreviewed_contract_cannot_receive_claim(): void
    {
        $doc = $this->contract(accept: false);
        $this->expectException(DomainException::class);
        $this->claim($doc);
    }

    public function test_mismatched_contract_reference_is_rejected(): void
    {
        $doc = $this->contract();
        $this->expectException(DomainException::class);
        $this->claim($doc, reference: str_repeat('d', 64));
    }

    public function test_source_expiry_cannot_outlive_contract(): void
    {
        $doc = $this->contract(until: CarbonImmutable::now('UTC')->addDay());
        $this->expectException(DomainException::class);
        $this->claim($doc);
    }

    public function test_superseded_contract_revision_rejects_new_claim(): void
    {
        $old = $this->contract();
        $this->contract(revision: 2);
        $this->expectException(DomainException::class);
        $this->claim($old);
    }

    public function test_unauthorized_user_cannot_submit(): void
    {
        $doc = $this->contract();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        $this->attestations->submit(
            (string) Str::uuid(), $doc['evidence_key'], 'signed_document',
            str_repeat('c', 64), str_repeat('b', 64),
            CarbonImmutable::now('UTC')->subDay(), null
        );
    }

    public function test_independent_reviewer_accepts_without_claiming_issuer_proof(): void
    {
        $claim = $this->claim($this->contract());
        $review = $this->review($claim);
        $this->assertSame('accepted', $review['decision']);
        $this->assertSame(404, $review['reviewed_by']);
        $this->assertFalse($review['issuer_independently_verified']);
        $this->assertFalse($review['ttn_submission_allowed']);
    }

    public function test_claim_submitter_cannot_review_even_if_gate_allows(): void
    {
        $claim = $this->claim($this->contract());
        Gate::define(NovaPoshtaCodExternalAttestationService::REVIEW_ABILITY,
            static fn ($user): bool => in_array((int) $user->getAuthIdentifier(), [303, 404], true));
        $this->expectException(DomainException::class);
        $this->attestations->review((string) Str::uuid(), $claim['claim_key'],
            'accepted', 'source_checked');
    }

    public function test_original_evidence_submitter_cannot_review_claim(): void
    {
        $claim = $this->claim($this->contract());
        Gate::define(NovaPoshtaCodExternalAttestationService::REVIEW_ABILITY,
            static fn ($user): bool => in_array((int) $user->getAuthIdentifier(), [101, 404], true));
        $this->actor(101);
        $this->expectException(DomainException::class);
        $this->attestations->review((string) Str::uuid(), $claim['claim_key'],
            'accepted', 'source_checked');
    }

    public function test_review_retry_is_idempotent(): void
    {
        $claim = $this->claim($this->contract());
        $key = (string) Str::uuid();
        $this->assertSame($this->review($claim, $key), $this->review($claim, $key));
        $this->assertDatabaseCount('np_cod_external_attestation_reviews', 1);
    }

    public function test_rejected_claim_remains_rejected(): void
    {
        $claim = $this->claim($this->contract());
        $this->review($claim, decision: 'rejected', reason: 'issuer_unconfirmed');
        $this->assertSame('rejected', $this->status->inspect('ua_primary')
        ['capabilities']['transport_agreement']['state']);
    }

    public function test_accepted_claim_is_current_only_in_internal_ledger(): void
    {
        $this->accepted();
        $result = $this->status->inspect('ua_primary');
        $this->assertSame('internally_attested',
            $result['capabilities']['transport_agreement']['state']);
        $this->assertFalse($result['issuer_contract_status_independently_verified']);
        $this->assertFalse($result['ttn_submission_allowed']);
    }

    public function test_all_three_attestations_still_cannot_approve_cod(): void
    {
        foreach (['transport_agreement', 'cod_agreement', 'payout_agreement'] as $capability) {
            $this->accepted($capability);
        }
        $result = $this->status->inspect('ua_primary');
        $this->assertTrue($result['all_internal_attestations_current']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
        $this->assertFalse($result['ttn_submission_allowed']);
    }

    public function test_accepted_claim_can_be_revoked_by_separate_actor(): void
    {
        $claim = $this->accepted();
        $revoked = $this->revoke($claim);
        $this->assertSame(505, $revoked['revoked_by']);
        $this->assertSame('revoked', $this->status->inspect('ua_primary')
        ['capabilities']['transport_agreement']['state']);
    }

    public function test_unreviewed_claim_cannot_be_revoked(): void
    {
        $claim = $this->claim($this->contract());
        $this->expectException(DomainException::class);
        $this->revoke($claim);
    }

    public function test_revocation_retry_is_idempotent(): void
    {
        $claim = $this->accepted();
        $key = (string) Str::uuid();
        $this->assertSame($this->revoke($claim, $key), $this->revoke($claim, $key));
    }

    public function test_claim_expires_without_database_mutation(): void
    {
        $doc = $this->contract();
        $claim = $this->claim($doc, until: CarbonImmutable::now('UTC')->addMinute());
        $this->review($claim);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(2));
        $this->assertSame('expired', $this->status->inspect('ua_primary')
        ['capabilities']['transport_agreement']['state']);
    }

    public function test_new_unreviewed_contract_revision_does_not_inherit_claim(): void
    {
        $this->accepted();
        $this->contract(revision: 2, accept: false);
        $result = $this->status->inspect('ua_primary');
        $this->assertFalse($result['all_internal_attestations_current']);
        $this->assertSame('contract_not_current',
            $result['capabilities']['transport_agreement']['state']);
    }

    public function test_database_rejects_direct_claim_update(): void
    {
        $claim = $this->claim($this->contract());
        $this->expectException(QueryException::class);
        DB::table('np_cod_external_attestation_claims')
            ->where('id', $claim['id'])->update(['submitted_by' => 999]);
    }

    public function test_database_rejects_direct_self_review(): void
    {
        $claim = $this->claim($this->contract());
        $this->expectException(QueryException::class);
        DB::table('np_cod_external_attestation_reviews')->insert([
            'operation_key' => (string) Str::uuid(), 'claim_id' => $claim['id'],
            'decision' => 'accepted', 'reason_code' => 'source_checked',
            'reviewed_by' => 303, 'request_sha256' => str_repeat('f', 64),
            'created_at' => now(),
        ]);
    }

    public function test_database_rejects_direct_claim_with_wrong_reference(): void
    {
        $doc = $this->contract();
        $this->expectException(QueryException::class);
        DB::table('np_cod_external_attestation_claims')->insert([
            'claim_key' => (string) Str::uuid(), 'evidence_id' => $doc['id'],
            'source_kind' => 'signed_document', 'source_sha256' => str_repeat('c', 64),
            'reference_sha256' => str_repeat('d', 64),
            'issued_at' => now()->subHour(), 'valid_until' => now()->addMonth(),
            'submitted_by' => 303, 'request_sha256' => str_repeat('e', 64),
            'created_at' => now(),
        ]);
    }

    public function test_profile_isolation_and_missing_claims(): void
    {
        $this->accepted();
        $other = $this->status->inspect('another_shop');
        $this->assertSame('contract_not_current',
            $other['capabilities']['transport_agreement']['state']);
        $this->assertFalse($other['all_internal_attestations_current']);
    }
}
