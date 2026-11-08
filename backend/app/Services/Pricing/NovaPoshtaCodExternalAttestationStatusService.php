<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only projection. Never converts a human claim into issuer API proof. */
final class NovaPoshtaCodExternalAttestationStatusService
{
    public function __construct(
        private readonly NovaPoshtaCodContractEvidencePolicy $contractPolicy,
        private readonly NovaPoshtaCodContractStatusService $contracts,
        private readonly NovaPoshtaCodExternalAttestationPolicy $policy,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $profileCode): array
    {
        $code = $this->contractPolicy->profileCode($profileCode);
        $contract = $this->contracts->inspect($code);
        $now = CarbonImmutable::now('UTC');
        $capabilities = [];

        foreach (NovaPoshtaCodContractEvidencePolicy::CAPABILITIES as $capability) {
            $doc = $contract['capabilities'][$capability];
            $evidenceId = $doc['latest_evidence_id'];
            $claim = $evidenceId === null ? null : DB::table('np_cod_external_attestation_claims')
                ->where('evidence_id', $evidenceId)->first();
            $review = $claim === null ? null : DB::table('np_cod_external_attestation_reviews')
                ->where('claim_id', $claim->id)->first(['decision']);
            $revoked = $claim !== null && DB::table('np_cod_external_attestation_revocations')
                    ->where('claim_id', $claim->id)->exists();
            $input = $claim === null ? null : [
                'issued_at' => (string) $claim->issued_at,
                'valid_until' => $claim->valid_until === null
                    ? null : (string) $claim->valid_until,
            ];
            $state = $this->policy->state(
                $input, $review?->decision, $revoked,
                $doc['internal_review_current'] === true, $now
            );
            $capabilities[$capability] = [
                'latest_evidence_id' => $evidenceId,
                'latest_claim_id' => $claim === null ? null : (int) $claim->id,
                'state' => $state,
                'internal_attestation_current' => $state === 'internally_attested',
            ];
        }

        $complete = array_reduce($capabilities,
            static fn (bool $all, array $item): bool =>
                $all && $item['internal_attestation_current'], true);

        return [
            'profile_code' => $code,
            'capabilities' => $capabilities,
            'all_internal_attestations_current' => $complete,
            'issuer_contract_status_independently_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
            'status' => 'trusted_issuer_confirmation_required',
        ];
    }
}
