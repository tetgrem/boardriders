<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaCodContractEvidenceService;
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

final class NovaPoshtaCodContractEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private NovaPoshtaCodContractEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new RuntimeException('Requires PostgreSQL boardriders_test');
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC'));
        $this->service = app(NovaPoshtaCodContractEvidenceService::class);
        Gate::define(NovaPoshtaCodContractEvidenceService::SUBMIT_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 101);
        Gate::define(NovaPoshtaCodContractEvidenceService::REVIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 202);
        Gate::define(NovaPoshtaCodContractEvidenceService::REVOKE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 303);
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

    private function submit(
        ?string $key = null,
        string $profile = 'ua_primary',
        string $capability = 'transport_agreement',
        int $revision = 1,
    ): array {
        return $this->service->submit(
            $key ?? (string) Str::uuid(), $profile, $capability, $revision,
            $capability === 'transport_agreement' ? 'nova_poshta' : 'novapay',
            str_repeat('a', 64), str_repeat('b', 64),
            CarbonImmutable::now('UTC')->subDay(),
            CarbonImmutable::now('UTC')->addYear(),
        );
    }

    private function review(string $evidenceKey, ?string $operationKey = null,
                            string $decision = 'accepted', string $reason = 'documents_reviewed'): array
    {
        $this->actor(202);
        return $this->service->review(
            $operationKey ?? (string) Str::uuid(), $evidenceKey, $decision, $reason
        );
    }

    private function revoke(string $evidenceKey, ?string $operationKey = null): array
    {
        $this->actor(303);
        return $this->service->revoke(
            $operationKey ?? (string) Str::uuid(), $evidenceKey, 'security_hold'
        );
    }

    public function test_submission_is_only_an_unverified_document_fingerprint(): void
    {
        $row = $this->submit();
        $this->assertSame(1, $row['revision']);
        $this->assertSame(101, $row['submitted_by']);
        $this->assertFalse($row['externally_verified']);
        $this->assertFalse($row['ready_for_dispatch']);
        $this->assertDatabaseCount('np_cod_contract_evidence', 1);
    }

    public function test_same_uuid_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $this->assertSame($this->submit($key), $this->submit($key));
        $this->assertDatabaseCount('np_cod_contract_evidence', 1);
    }

    public function test_same_uuid_cannot_change_profile(): void
    {
        $key = (string) Str::uuid();
        $this->submit($key);
        $this->expectException(DomainException::class);
        $this->submit($key, 'second_profile');
    }

    public function test_revisions_must_be_contiguous(): void
    {
        $this->submit();
        $this->expectException(DomainException::class);
        $this->submit(revision: 3);
    }

    public function test_new_revision_preserves_original_history(): void
    {
        $first = $this->submit();
        $second = $this->submit(revision: 2);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseCount('np_cod_contract_evidence', 2);
    }

    public function test_user_without_submit_permission_is_denied(): void
    {
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        $this->submit();
    }

    public function test_reviewer_cannot_submit(): void
    {
        $this->actor(202);
        $this->expectException(AuthorizationException::class);
        $this->submit();
    }

    public function test_submitter_cannot_review_own_evidence_even_with_gate_permission(): void
    {
        $row = $this->submit();
        Gate::define(NovaPoshtaCodContractEvidenceService::REVIEW_ABILITY,
            static fn ($user): bool => in_array((int) $user->getAuthIdentifier(), [101, 202], true));
        $this->expectException(DomainException::class);
        $this->service->review((string) Str::uuid(), $row['evidence_key'], 'accepted', 'documents_reviewed');
    }

    public function test_different_authorized_reviewer_can_accept_evidence(): void
    {
        $row = $this->submit();
        $review = $this->review($row['evidence_key']);
        $this->assertSame('accepted', $review['decision']);
        $this->assertSame(202, $review['reviewed_by']);
        $this->assertFalse($review['externally_verified']);
        $this->assertDatabaseCount('np_cod_contract_reviews', 1);
    }

    public function test_review_retry_is_idempotent(): void
    {
        $row = $this->submit();
        $key = (string) Str::uuid();
        $this->assertSame(
            $this->review($row['evidence_key'], $key),
            $this->review($row['evidence_key'], $key)
        );
        $this->assertDatabaseCount('np_cod_contract_reviews', 1);
    }

    public function test_changed_review_key_cannot_override_existing_decision(): void
    {
        $row = $this->submit();
        $this->review($row['evidence_key']);
        $this->expectException(DomainException::class);
        $this->review($row['evidence_key']);
    }

    public function test_rejection_is_immutable_and_audited(): void
    {
        $row = $this->submit();
        $result = $this->review($row['evidence_key'], decision: 'rejected', reason: 'reference_mismatch');
        $this->assertSame('rejected', $result['decision']);
        $this->assertSame('reference_mismatch', $result['reason_code']);
    }

    public function test_unreviewed_evidence_cannot_be_revoked(): void
    {
        $row = $this->submit();
        $this->expectException(DomainException::class);
        $this->revoke($row['evidence_key']);
    }

    public function test_rejected_evidence_cannot_be_revoked_as_accepted(): void
    {
        $row = $this->submit();
        $this->review($row['evidence_key'], decision: 'rejected', reason: 'missing_document');
        $this->expectException(DomainException::class);
        $this->revoke($row['evidence_key']);
    }

    public function test_authorized_revocation_is_append_only(): void
    {
        $row = $this->submit();
        $this->review($row['evidence_key']);
        $revocation = $this->revoke($row['evidence_key']);
        $this->assertSame(303, $revocation['revoked_by']);
        $this->assertSame('security_hold', $revocation['reason_code']);
        $this->assertDatabaseCount('np_cod_contract_revocations', 1);
    }

    public function test_revocation_retry_with_same_key_is_idempotent(): void
    {
        $row = $this->submit();
        $this->review($row['evidence_key']);
        $key = (string) Str::uuid();
        $this->assertSame($this->revoke($row['evidence_key'], $key),
            $this->revoke($row['evidence_key'], $key));
    }

    public function test_revocation_replacement_key_is_rejected(): void
    {
        $row = $this->submit();
        $this->review($row['evidence_key']);
        $this->revoke($row['evidence_key']);
        $this->expectException(DomainException::class);
        $this->revoke($row['evidence_key']);
    }

    public function test_user_without_revoke_permission_cannot_revoke(): void
    {
        $row = $this->submit();
        $this->review($row['evidence_key']);
        $this->actor(202);
        $this->expectException(AuthorizationException::class);
        $this->service->revoke((string) Str::uuid(), $row['evidence_key'], 'security_hold');
    }

    public function test_database_cannot_rewrite_evidence(): void
    {
        $row = $this->submit();
        $this->expectException(QueryException::class);
        DB::table('np_cod_contract_evidence')->where('id', $row['id'])
            ->update(['revision' => 8]);
    }

    public function test_database_cannot_rewrite_review(): void
    {
        $row = $this->submit();
        $review = $this->review($row['evidence_key']);
        $this->expectException(QueryException::class);
        DB::table('np_cod_contract_reviews')->where('id', $review['id'])
            ->update(['decision' => 'rejected']);
    }

    public function test_database_cannot_insert_self_review(): void
    {
        $row = $this->submit();
        $this->expectException(QueryException::class);
        DB::table('np_cod_contract_reviews')->insert([
            'operation_key' => (string) Str::uuid(),
            'evidence_id' => $row['id'],
            'decision' => 'accepted', 'reason_code' => 'documents_reviewed',
            'reviewed_by' => 101,
            'request_sha256' => str_repeat('c', 64),
            'created_at' => now(),
        ]);
    }

    public function test_database_cannot_skip_revision(): void
    {
        $this->submit();
        $this->expectException(QueryException::class);
        DB::table('np_cod_contract_evidence')->insert([
            'evidence_key' => (string) Str::uuid(),
            'profile_code' => 'ua_primary',
            'capability' => 'transport_agreement',
            'revision' => 3, 'issuer_code' => 'nova_poshta',
            'document_sha256' => str_repeat('a', 64),
            'reference_sha256' => str_repeat('b', 64),
            'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(),
            'submitted_by' => 101,
            'request_sha256' => str_repeat('c', 64),
            'created_at' => now(),
        ]);
    }
}
