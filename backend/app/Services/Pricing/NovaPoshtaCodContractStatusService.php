<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only internal status of the latest evidence revision per capability.
 * A manual review remains INTERNAL, not a carrier/NovaPay attestation.
 * Do not expose this by an unauthenticated route.
 */
final class NovaPoshtaCodContractStatusService
{
    public function __construct(
        private readonly NovaPoshtaCodContractEvidencePolicy $policy,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $profileCode): array
    {
        $profileCode = $this->policy->profileCode($profileCode);
        $now = CarbonImmutable::now('UTC');
        $capabilities = [];

        foreach (NovaPoshtaCodContractEvidencePolicy::CAPABILITIES as $capability) {
            $evidence = DB::table('np_cod_contract_evidence')
                ->where('profile_code', $profileCode)
                ->where('capability', $capability)
                ->orderByDesc('revision')->first();

            $review = $evidence === null ? null : DB::table('np_cod_contract_reviews')
                ->where('evidence_id', $evidence->id)->first(['decision']);
            $revoked = $evidence !== null && DB::table('np_cod_contract_revocations')
                    ->where('evidence_id', $evidence->id)->exists();

            $record = $evidence === null ? null : [
                'valid_from' => (string) $evidence->valid_from,
                'valid_until' => $evidence->valid_until === null
                    ? null : (string) $evidence->valid_until,
            ];
            $state = $this->policy->state(
                $record, $review?->decision, $revoked, $now
            );

            $capabilities[$capability] = [
                'latest_evidence_id' => $evidence?->id === null
                    ? null : (int) $evidence->id,
                'latest_revision' => $evidence?->revision === null
                    ? null : (int) $evidence->revision,
                'state' => $state,
                'internal_review_current' => $state === 'internally_accepted',
            ];
        }

        $allReviewed = array_reduce(
            $capabilities,
            static fn (bool $carry, array $entry): bool =>
                $carry && $entry['internal_review_current'],
            true
        );

        return [
            'profile_code' => $profileCode,
            'capabilities' => $capabilities,
            'all_internal_reviews_current' => $allReviewed,
            'carrier_contracts_api_verified' => false,
            'payout_contract_api_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
            'status' => 'external_confirmation_required',
        ];
    }
}
