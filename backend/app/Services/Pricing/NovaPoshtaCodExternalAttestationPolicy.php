<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** An operator's review of issuer material is NOT a live issuer verification. */
final class NovaPoshtaCodExternalAttestationPolicy
{
    public function sourceKind(string $value): string
    {
        if (!in_array($value, [
            'signed_document', 'issuer_portal_export', 'issuer_correspondence',
        ], true)) {
            throw new InvalidArgumentException('Invalid COD attestation source kind');
        }
        return $value;
    }

    public function sha256(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('Expected lowercase SHA-256');
        }
        return $value;
    }

    /** @return array{0:string,1:?string} */
    public function period(DateTimeInterface $issued, ?DateTimeInterface $expires): array
    {
        $from = CarbonImmutable::instance($issued)->utc();
        $until = $expires === null ? null : CarbonImmutable::instance($expires)->utc();
        if ($until !== null && $until->lessThanOrEqualTo($from)) {
            throw new InvalidArgumentException('Attestation expiry must follow issuance');
        }
        return [
            $from->format('Y-m-d H:i:s.uP'),
            $until?->format('Y-m-d H:i:s.uP'),
        ];
    }

    public function reviewReason(string $decision, string $reason): void
    {
        $allowed = match ($decision) {
            'accepted' => ['source_checked'],
            'rejected' => [
                'missing_source', 'reference_mismatch', 'issuer_unconfirmed',
                'invalid_scope', 'other_issue',
            ],
            default => [],
        };
        if (!in_array($reason, $allowed, true)) {
            throw new InvalidArgumentException('Invalid attestation review decision/reason');
        }
    }

    public function revocationReason(string $reason): void
    {
        if (!in_array($reason, [
            'issuer_withdrawal', 'source_invalidated', 'security_hold', 'contract_changed',
        ], true)) {
            throw new InvalidArgumentException('Invalid attestation revocation reason');
        }
    }

    /**
     * @param array{issued_at:string,valid_until:?string}|null $claim
     * Latest contract state is supplied separately and cannot be inherited.
     */
    public function state(
        ?array $claim,
        ?string $review,
        bool $revoked,
        bool $contractCurrent,
        CarbonImmutable $now,
    ): string {
        if (!$contractCurrent) {
            return 'contract_not_current';
        }
        if ($claim === null) {
            return 'missing';
        }
        if ($revoked) {
            return 'revoked';
        }
        if ($review === 'rejected') {
            return 'rejected';
        }
        if ($review === null) {
            return 'pending_review';
        }
        if ($review !== 'accepted') {
            return 'invalid_review';
        }
        if (CarbonImmutable::parse($claim['issued_at'])->greaterThan($now)) {
            return 'not_yet_issued';
        }
        if ($claim['valid_until'] !== null &&
            CarbonImmutable::parse($claim['valid_until'])->lessThanOrEqualTo($now)) {
            return 'expired';
        }
        return 'internally_attested';
    }
}
