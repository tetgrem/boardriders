<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaCodContractEvidenceService;
use App\Services\Pricing\NovaPoshtaCodContractStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class NovaPoshtaCodContractStatusTest extends TestCase
{
    use RefreshDatabase;

    private NovaPoshtaCodContractEvidenceService $evidence;
    private NovaPoshtaCodContractStatusService $status;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new RuntimeException('Requires PostgreSQL boardriders_test');
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC'));
        $this->evidence = app(NovaPoshtaCodContractEvidenceService::class);
        $this->status = app(NovaPoshtaCodContractStatusService::class);
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

    private function record(
        string $capability = 'transport_agreement',
        int $revision = 1,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null,
        string $profile = 'ua_primary',
    ): array {
        $this->actor(101);
        return $this->evidence->submit(
            (string) Str::uuid(), $profile, $capability, $revision,
            $capability === 'transport_agreement' ? 'nova_poshta' : 'novapay',
            str_repeat('a', 64), str_repeat('b', 64),
            $from ?? CarbonImmutable::now('UTC')->subDay(),
            $until,
        );
    }

    private function accept(string $evidenceKey): void
    {
        $this->actor(202);
        $this->evidence->review(
            (string) Str::uuid(), $evidenceKey, 'accepted', 'documents_reviewed'
        );
    }

    private function complete(): array
    {
        foreach (['transport_agreement', 'cod_agreement', 'payout_agreement'] as $capability) {
            $row = $this->record($capability);
            $this->accept($row['evidence_key']);
        }
        return $this->status->inspect('ua_primary');
    }

    public function test_missing_evidence_fails_closed_for_all_capabilities(): void
    {
        $result = $this->status->inspect('ua_primary');
        foreach ($result['capabilities'] as $entry) {
            $this->assertSame('missing', $entry['state']);
        }
        $this->assertFalse($result['all_internal_reviews_current']);
        $this->assertFalse($result['ttn_submission_allowed']);
    }

    public function test_pending_review_is_not_internally_current(): void
    {
        $this->record();
        $result = $this->status->inspect('ua_primary');
        $this->assertSame('pending_review', $result['capabilities']['transport_agreement']['state']);
        $this->assertFalse($result['all_internal_reviews_current']);
    }

    public function test_one_accepted_capability_does_not_cover_other_contracts(): void
    {
        $row = $this->record();
        $this->accept($row['evidence_key']);
        $result = $this->status->inspect('ua_primary');
        $this->assertTrue($result['capabilities']['transport_agreement']['internal_review_current']);
        $this->assertSame('missing', $result['capabilities']['cod_agreement']['state']);
        $this->assertFalse($result['all_internal_reviews_current']);
    }

    public function test_three_internal_acceptances_never_allow_ttn_or_cod(): void
    {
        $result = $this->complete();
        $this->assertTrue($result['all_internal_reviews_current']);
        $this->assertFalse($result['carrier_contracts_api_verified']);
        $this->assertFalse($result['payout_contract_api_verified']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
        $this->assertFalse($result['ttn_submission_allowed']);
        $this->assertFalse($result['ready_for_dispatch']);
    }

    public function test_revoked_latest_evidence_blocks_internal_completeness(): void
    {
        $result = $this->complete();
        $targetId = $result['capabilities']['cod_agreement']['latest_evidence_id'];
        $key = (string) DB::table('np_cod_contract_evidence')
            ->where('id', $targetId)->value('evidence_key');
        $this->actor(303);
        $this->evidence->revoke((string) Str::uuid(), $key, 'contract_terminated');
        $updated = $this->status->inspect('ua_primary');
        $this->assertSame('revoked', $updated['capabilities']['cod_agreement']['state']);
        $this->assertFalse($updated['all_internal_reviews_current']);
    }

    public function test_expired_accepted_document_is_not_current(): void
    {
        $row = $this->record(until: CarbonImmutable::now('UTC')->addMinute());
        $this->accept($row['evidence_key']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(2));
        $result = $this->status->inspect('ua_primary');
        $this->assertSame('expired', $result['capabilities']['transport_agreement']['state']);
    }

    public function test_future_dated_accepted_document_is_not_current(): void
    {
        $row = $this->record(from: CarbonImmutable::now('UTC')->addDay());
        $this->accept($row['evidence_key']);
        $result = $this->status->inspect('ua_primary');
        $this->assertSame('not_yet_valid', $result['capabilities']['transport_agreement']['state']);
    }

    public function test_new_pending_revision_never_inherits_prior_acceptance(): void
    {
        $old = $this->record();
        $this->accept($old['evidence_key']);
        $this->record(revision: 2);
        $result = $this->status->inspect('ua_primary');
        $this->assertSame(2, $result['capabilities']['transport_agreement']['latest_revision']);
        $this->assertSame('pending_review', $result['capabilities']['transport_agreement']['state']);
    }

    public function test_rejected_new_revision_never_falls_back_to_old_accepted(): void
    {
        $old = $this->record();
        $this->accept($old['evidence_key']);
        $new = $this->record(revision: 2);
        $this->actor(202);
        $this->evidence->review((string) Str::uuid(), $new['evidence_key'],
            'rejected', 'reference_mismatch');
        $result = $this->status->inspect('ua_primary');
        $this->assertSame('rejected', $result['capabilities']['transport_agreement']['state']);
    }

    public function test_profiles_are_isolated(): void
    {
        $row = $this->record(profile: 'ua_primary');
        $this->accept($row['evidence_key']);
        $other = $this->status->inspect('other_store');
        $this->assertSame('missing', $other['capabilities']['transport_agreement']['state']);
    }

    public function test_report_is_read_only_and_deterministic(): void
    {
        $this->complete();
        $before = $this->status->inspect('ua_primary');
        $after = $this->status->inspect('ua_primary');
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('np_cod_contract_evidence', 3);
        $this->assertDatabaseCount('np_cod_contract_reviews', 3);
        $this->assertDatabaseCount('np_cod_contract_revocations', 0);
    }
}
