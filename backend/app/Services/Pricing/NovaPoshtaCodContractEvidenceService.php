<?php

namespace App\Services\Pricing;

use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal administrative workflow only. Register Gate abilities in the host
 * admin authorization system before calling it; undefined abilities DENY.
 * No public routes, carrier mutations, payments, or TTN submission.
 */
final class NovaPoshtaCodContractEvidenceService
{
    public const SUBMIT_ABILITY = 'nova-poshta.cod-contract-evidence.submit';
    public const REVIEW_ABILITY = 'nova-poshta.cod-contract-evidence.review';
    public const REVOKE_ABILITY = 'nova-poshta.cod-contract-evidence.revoke';

    public function __construct(
        private readonly NovaPoshtaCodContractEvidencePolicy $policy,
    ) {
    }

    /** @return array<string, mixed> */
    public function submit(
        string $evidenceKey,
        string $profileCode,
        string $capability,
        int $revision,
        string $issuerCode,
        string $documentSha256,
        string $referenceSha256,
        DateTimeInterface $validFrom,
        ?DateTimeInterface $validUntil,
    ): array {
        $actor = $this->actor(self::SUBMIT_ABILITY);
        $key = $this->uuid($evidenceKey);
        $profile = $this->policy->profileCode($profileCode);
        $this->policy->capability($capability, $issuerCode);
        $documentSha256 = $this->policy->hash($documentSha256);
        $referenceSha256 = $this->policy->hash($referenceSha256);
        if ($revision < 1) {
            throw new InvalidArgumentException('Invalid evidence revision');
        }
        [$from, $until] = $this->policy->period($validFrom, $validUntil);
        $sha = $this->digest([
            'np-cod-evidence-v1', $actor, $profile, $capability, $revision,
            $issuerCode, $documentSha256, $referenceSha256, $from, $until,
        ]);

        return DB::transaction(function () use (
            $actor, $key, $profile, $capability, $revision,
            $issuerCode, $documentSha256, $referenceSha256, $from, $until, $sha
        ): array {
            $this->serializeScope($profile, $capability);
            $existing = DB::table('np_cod_contract_evidence')
                ->where('evidence_key', $key)->first();
            if ($existing !== null) {
                $this->matchRetry($existing, 'request_sha256', $sha);
                return $this->presentEvidence($existing);
            }
            $latest = DB::table('np_cod_contract_evidence')
                ->where('profile_code', $profile)
                ->where('capability', $capability)
                ->max('revision');
            if ($revision !== (int) $latest + 1) {
                throw new DomainException('Evidence revision must follow latest revision');
            }
            $id = DB::table('np_cod_contract_evidence')->insertGetId([
                'evidence_key' => $key,
                'profile_code' => $profile,
                'capability' => $capability,
                'revision' => $revision,
                'issuer_code' => $issuerCode,
                'document_sha256' => $documentSha256,
                'reference_sha256' => $referenceSha256,
                'valid_from' => $from,
                'valid_until' => $until,
                'submitted_by' => $actor,
                'request_sha256' => $sha,
                'created_at' => now(),
            ]);
            return $this->presentEvidence(
                DB::table('np_cod_contract_evidence')->where('id', $id)->first()
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    public function review(
        string $operationKey,
        string $evidenceKey,
        string $decision,
        string $reasonCode,
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);
        $key = $this->uuid($operationKey);
        $evidenceKey = $this->uuid($evidenceKey);
        $this->policy->decisionReason($decision, $reasonCode);
        $sha = $this->digest([
            'np-cod-review-v1', $actor, $evidenceKey, $decision, $reasonCode,
        ]);

        return DB::transaction(function () use (
            $actor, $key, $evidenceKey, $decision, $reasonCode, $sha
        ): array {
            $evidence = DB::table('np_cod_contract_evidence')
                ->where('evidence_key', $evidenceKey)->lockForUpdate()->first();
            if ($evidence === null) {
                throw new DomainException('Contract evidence does not exist');
            }
            if ((int) $evidence->submitted_by === $actor) {
                throw new DomainException('Submitter cannot review their own evidence');
            }
            $existing = DB::table('np_cod_contract_reviews')
                ->where('evidence_id', $evidence->id)->first();
            if ($existing !== null) {
                if ((string) $existing->operation_key !== $key) {
                    throw new DomainException('Evidence already has a review');
                }
                $this->matchRetry($existing, 'request_sha256', $sha);
                return $this->presentReview($existing);
            }
            if (DB::table('np_cod_contract_reviews')->where('operation_key', $key)->exists()) {
                throw new DomainException('Review UUID already used');
            }
            $id = DB::table('np_cod_contract_reviews')->insertGetId([
                'operation_key' => $key,
                'evidence_id' => $evidence->id,
                'decision' => $decision,
                'reason_code' => $reasonCode,
                'reviewed_by' => $actor,
                'request_sha256' => $sha,
                'created_at' => now(),
            ]);
            return $this->presentReview(
                DB::table('np_cod_contract_reviews')->where('id', $id)->first()
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    public function revoke(string $operationKey, string $evidenceKey, string $reasonCode): array
    {
        $actor = $this->actor(self::REVOKE_ABILITY);
        $key = $this->uuid($operationKey);
        $evidenceKey = $this->uuid($evidenceKey);
        $this->policy->revocationReason($reasonCode);
        $sha = $this->digest(['np-cod-revoke-v1', $actor, $evidenceKey, $reasonCode]);

        return DB::transaction(function () use (
            $actor, $key, $evidenceKey, $reasonCode, $sha
        ): array {
            $evidence = DB::table('np_cod_contract_evidence')
                ->where('evidence_key', $evidenceKey)->lockForUpdate()->first();
            if ($evidence === null) {
                throw new DomainException('Contract evidence does not exist');
            }
            $existing = DB::table('np_cod_contract_revocations')
                ->where('evidence_id', $evidence->id)->first();
            if ($existing !== null) {
                if ((string) $existing->operation_key !== $key) {
                    throw new DomainException('Evidence already revoked');
                }
                $this->matchRetry($existing, 'request_sha256', $sha);
                return $this->presentRevocation($existing);
            }
            $accepted = DB::table('np_cod_contract_reviews')
                ->where('evidence_id', $evidence->id)
                ->where('decision', 'accepted')->exists();
            if (!$accepted) {
                throw new DomainException('Only accepted evidence can be revoked');
            }
            if (DB::table('np_cod_contract_revocations')->where('operation_key', $key)->exists()) {
                throw new DomainException('Revocation UUID already used');
            }
            $id = DB::table('np_cod_contract_revocations')->insertGetId([
                'operation_key' => $key,
                'evidence_id' => $evidence->id,
                'reason_code' => $reasonCode,
                'revoked_by' => $actor,
                'request_sha256' => $sha,
                'created_at' => now(),
            ]);
            return $this->presentRevocation(
                DB::table('np_cod_contract_revocations')->where('id', $id)->first()
            );
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
            || (int) $id <= 0 || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException('Contract governance permission denied');
        }
        return (int) $id;
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException('Invalid governance operation UUID');
        }
        return strtolower($value);
    }

    private function digest(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function serializeScope(string $profile, string $capability): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
            'np-cod-contract:' . $profile . ':' . $capability,
        ]);
    }

    private function matchRetry(object $row, string $field, string $sha): void
    {
        if (!hash_equals((string) $row->{$field}, $sha)) {
            throw new DomainException('Governance UUID reused with changed request');
        }
    }

    private function presentEvidence(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'evidence_key' => (string) $row->evidence_key,
            'profile_code' => (string) $row->profile_code,
            'capability' => (string) $row->capability,
            'revision' => (int) $row->revision,
            'issuer_code' => (string) $row->issuer_code,
            'document_sha256' => (string) $row->document_sha256,
            'reference_sha256' => (string) $row->reference_sha256,
            'valid_from' => (string) $row->valid_from,
            'valid_until' => $row->valid_until === null ? null : (string) $row->valid_until,
            'submitted_by' => (int) $row->submitted_by,
            'reviewed_by' => null,
            'externally_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
        ];
    }

    private function presentReview(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'evidence_id' => (int) $row->evidence_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'externally_verified' => false,
            'ready_for_dispatch' => false,
        ];
    }

    private function presentRevocation(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'evidence_id' => (int) $row->evidence_id,
            'reason_code' => (string) $row->reason_code,
            'revoked_by' => (int) $row->revoked_by,
            'ready_for_dispatch' => false,
        ];
    }
}
