<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Authenticated back-office claim/review/revocation ledger. No provider API calls.
 * Human assessment of a document != live carrier/NovaPay verification.
 */
final class NovaPoshtaCodExternalAttestationService
{
    public const SUBMIT_ABILITY = 'nova-poshta.cod-external-attestation.submit';
    public const REVIEW_ABILITY = 'nova-poshta.cod-external-attestation.review';
    public const REVOKE_ABILITY = 'nova-poshta.cod-external-attestation.revoke';

    public function __construct(
        private readonly NovaPoshtaCodExternalAttestationPolicy $policy,
        private readonly NovaPoshtaCodContractStatusService $contracts,
    ) {
    }

    /** @return array<string, mixed> */
    public function submit(
        string $claimKey,
        string $evidenceKey,
        string $sourceKind,
        string $sourceSha256,
        string $referenceSha256,
        DateTimeInterface $issuedAt,
        ?DateTimeInterface $validUntil,
    ): array {
        $actor = $this->actor(self::SUBMIT_ABILITY);
        $claimKey = $this->uuid($claimKey);
        $evidenceKey = $this->uuid($evidenceKey);
        $sourceKind = $this->policy->sourceKind($sourceKind);
        $sourceSha256 = $this->policy->sha256($sourceSha256);
        $referenceSha256 = $this->policy->sha256($referenceSha256);
        [$issued, $until] = $this->policy->period($issuedAt, $validUntil);
        $sha = $this->digest([
            'np-cod-external-claim-v1', $actor, $evidenceKey, $sourceKind,
            $sourceSha256, $referenceSha256, $issued, $until,
        ]);

        return DB::transaction(function () use (
            $actor, $claimKey, $evidenceKey, $sourceKind, $sourceSha256,
            $referenceSha256, $issued, $until, $sha
        ): array {
            $evidence = DB::table('np_cod_contract_evidence')
                ->where('evidence_key', $evidenceKey)->lockForUpdate()->first();
            if ($evidence === null) {
                throw new DomainException('Underlying COD contract evidence not found');
            }
            $existing = DB::table('np_cod_external_attestation_claims')
                ->where('claim_key', $claimKey)->first();
            if ($existing !== null) {
                if ((int) $existing->evidence_id !== (int) $evidence->id) {
                    throw new DomainException('Claim UUID already used for another contract');
                }
                $this->sameRequest($existing, $sha);
                return $this->presentClaim($existing);
            }
            if (DB::table('np_cod_external_attestation_claims')
                ->where('evidence_id', $evidence->id)->exists()) {
                throw new DomainException('This contract revision already has a claim');
            }
            $status = $this->contracts->inspect((string) $evidence->profile_code);
            $current = $status['capabilities'][$evidence->capability];
            if ($current['latest_evidence_id'] !== (int) $evidence->id
                || $current['internal_review_current'] !== true
                || !hash_equals((string) $evidence->reference_sha256, $referenceSha256)
            ) {
                throw new DomainException('Contract revision is not current or reference differs');
            }
            $now = CarbonImmutable::now('UTC');
            if (CarbonImmutable::parse($issued)->greaterThan($now)
                || ($until !== null && CarbonImmutable::parse($until)->lessThanOrEqualTo($now))
                || ($evidence->valid_until !== null &&
                    ($until === null || CarbonImmutable::parse($until)
                            ->greaterThan(CarbonImmutable::parse((string) $evidence->valid_until))))
            ) {
                throw new DomainException('Claim validity is inconsistent with contract');
            }
            $id = DB::table('np_cod_external_attestation_claims')->insertGetId([
                'claim_key' => $claimKey,
                'evidence_id' => $evidence->id,
                'source_kind' => $sourceKind,
                'source_sha256' => $sourceSha256,
                'reference_sha256' => $referenceSha256,
                'issued_at' => $issued,
                'valid_until' => $until,
                'submitted_by' => $actor,
                'request_sha256' => $sha,
                'created_at' => now(),
            ]);
            return $this->presentClaim(DB::table('np_cod_external_attestation_claims')
                ->where('id', $id)->first());
        }, 3);
    }

    /** @return array<string, mixed> */
    public function review(
        string $operationKey, string $claimKey, string $decision, string $reason,
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);
        $operationKey = $this->uuid($operationKey);
        $claimKey = $this->uuid($claimKey);
        $this->policy->reviewReason($decision, $reason);
        $sha = $this->digest([
            'np-cod-external-review-v1', $actor, $claimKey, $decision, $reason,
        ]);

        return DB::transaction(function () use (
            $actor, $operationKey, $claimKey, $decision, $reason, $sha
        ): array {
            $claim = DB::table('np_cod_external_attestation_claims')
                ->where('claim_key', $claimKey)->lockForUpdate()->first();
            if ($claim === null) {
                throw new DomainException('External claim not found');
            }
            $evidence = DB::table('np_cod_contract_evidence')
                ->where('id', $claim->evidence_id)->first();
            if ((int) $claim->submitted_by === $actor
                || $evidence === null || (int) $evidence->submitted_by === $actor) {
                throw new DomainException('Independent claim reviewer required');
            }
            $existing = DB::table('np_cod_external_attestation_reviews')
                ->where('claim_id', $claim->id)->first();
            if ($existing !== null) {
                if ((string) $existing->operation_key !== $operationKey) {
                    throw new DomainException('Claim has already been reviewed');
                }
                $this->sameRequest($existing, $sha);
                return $this->presentReview($existing);
            }
            if (DB::table('np_cod_external_attestation_reviews')
                ->where('operation_key', $operationKey)->exists()) {
                throw new DomainException('Review operation UUID already used');
            }
            if ($decision === 'accepted') {
                $status = $this->contracts->inspect((string) $evidence->profile_code);
                $current = $status['capabilities'][$evidence->capability];
                $now = CarbonImmutable::now('UTC');
                if ($current['latest_evidence_id'] !== (int) $evidence->id
                    || $current['internal_review_current'] !== true
                    || CarbonImmutable::parse((string) $claim->issued_at)->greaterThan($now)
                    || ($claim->valid_until !== null &&
                        CarbonImmutable::parse((string) $claim->valid_until)->lessThanOrEqualTo($now))) {
                    throw new DomainException('Claim is no longer current for acceptance');
                }
            }
            $id = DB::table('np_cod_external_attestation_reviews')->insertGetId([
                'operation_key' => $operationKey,
                'claim_id' => $claim->id,
                'decision' => $decision,
                'reason_code' => $reason,
                'reviewed_by' => $actor,
                'request_sha256' => $sha,
                'created_at' => now(),
            ]);
            return $this->presentReview(DB::table('np_cod_external_attestation_reviews')
                ->where('id', $id)->first());
        }, 3);
    }

    /** @return array<string, mixed> */
    public function revoke(string $operationKey, string $claimKey, string $reason): array
    {
        $actor = $this->actor(self::REVOKE_ABILITY);
        $operationKey = $this->uuid($operationKey);
        $claimKey = $this->uuid($claimKey);
        $this->policy->revocationReason($reason);
        $sha = $this->digest(['np-cod-external-revoke-v1', $actor, $claimKey, $reason]);

        return DB::transaction(function () use (
            $actor, $operationKey, $claimKey, $reason, $sha
        ): array {
            $claim = DB::table('np_cod_external_attestation_claims')
                ->where('claim_key', $claimKey)->lockForUpdate()->first();
            if ($claim === null) {
                throw new DomainException('External claim not found');
            }
            $review = DB::table('np_cod_external_attestation_reviews')
                ->where('claim_id', $claim->id)->first();
            if ($review === null || $review->decision !== 'accepted'
                || (int) $claim->submitted_by === $actor
                || (int) $review->reviewed_by === $actor) {
                throw new DomainException('Independent revoker and accepted claim required');
            }
            $existing = DB::table('np_cod_external_attestation_revocations')
                ->where('claim_id', $claim->id)->first();
            if ($existing !== null) {
                if ((string) $existing->operation_key !== $operationKey) {
                    throw new DomainException('External claim already revoked');
                }
                $this->sameRequest($existing, $sha);
                return $this->presentRevocation($existing);
            }
            if (DB::table('np_cod_external_attestation_revocations')
                ->where('operation_key', $operationKey)->exists()) {
                throw new DomainException('Revocation operation UUID already used');
            }
            $id = DB::table('np_cod_external_attestation_revocations')->insertGetId([
                'operation_key' => $operationKey,
                'claim_id' => $claim->id,
                'reason_code' => $reason,
                'revoked_by' => $actor,
                'request_sha256' => $sha,
                'created_at' => now(),
            ]);
            return $this->presentRevocation(DB::table('np_cod_external_attestation_revocations')
                ->where('id', $id)->first());
        }, 3);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        if ($user === null || !method_exists($user, 'getAuthIdentifier')) {
            throw new AuthorizationException('Authenticated administrator required');
        }
        $id = $user->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('COD external attestation permission denied');
        }
        return (int) $id;
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException('Invalid external attestation UUID');
        }
        return strtolower($value);
    }

    private function digest(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function sameRequest(object $row, string $sha): void
    {
        if (!hash_equals((string) $row->request_sha256, $sha)) {
            throw new DomainException('Attestation operation UUID reused with changed data');
        }
    }

    /** @return array<string, mixed> */
    private function presentClaim(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'claim_key' => (string) $row->claim_key,
            'evidence_id' => (int) $row->evidence_id,
            'source_kind' => (string) $row->source_kind,
            'source_sha256' => (string) $row->source_sha256,
            'issued_at' => (string) $row->issued_at,
            'valid_until' => $row->valid_until === null ? null : (string) $row->valid_until,
            'submitted_by' => (int) $row->submitted_by,
            'issuer_independently_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ttn_submission_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function presentReview(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'claim_id' => (int) $row->claim_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'issuer_independently_verified' => false,
            'ttn_submission_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRevocation(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'claim_id' => (int) $row->claim_id,
            'reason_code' => (string) $row->reason_code,
            'revoked_by' => (int) $row->revoked_by,
            'ttn_submission_allowed' => false,
        ];
    }
}
