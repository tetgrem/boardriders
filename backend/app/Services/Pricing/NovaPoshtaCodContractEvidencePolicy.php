<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** Pure, fail-closed policy. Internal acceptance is never carrier approval. */
final class NovaPoshtaCodContractEvidencePolicy
{
    public const CAPABILITIES = [
        'transport_agreement',
        'cod_agreement',
        'payout_agreement',
    ];

    public function profileCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D', $code)) {
            throw new InvalidArgumentException('Invalid sender profile code');
        }
        return $code;
    }

    public function capability(string $capability, string $issuer): void
    {
        if (!in_array($capability, self::CAPABILITIES, true)) {
            throw new InvalidArgumentException('Unknown COD agreement capability');
        }
        $requiredIssuer = $capability === 'transport_agreement'
            ? 'nova_poshta' : 'novapay';
        if ($issuer !== $requiredIssuer) {
            throw new InvalidArgumentException('Unexpected agreement issuer');
        }
    }

    public function hash(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('Document hash must be lowercase SHA-256');
        }
        return $value;
    }

    public function period(DateTimeInterface $from, ?DateTimeInterface $until): array
    {
        $start = CarbonImmutable::instance($from)->utc();
        $end = $until === null ? null : CarbonImmutable::instance($until)->utc();
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('Evidence end must follow start');
        }
        return [
            $start->format('Y-m-d H:i:s.uP'),
            $end?->format('Y-m-d H:i:s.uP'),
        ];
    }

    public function decisionReason(string $decision, string $reason): void
    {
        $allowed = $decision === 'accepted'
            ? ['documents_reviewed']
            : ($decision === 'rejected'
                ? ['missing_document', 'reference_mismatch', 'insufficient_scope', 'other_issue']
                : []);
        if (!in_array($reason, $allowed, true)) {
            throw new InvalidArgumentException('Invalid document review decision/reason');
        }
    }

    public function revocationReason(string $reason): void
    {
        if (!in_array($reason, [
            'contract_terminated', 'issuer_notice', 'security_hold', 'invalid_evidence',
        ], true)) {
            throw new InvalidArgumentException('Invalid COD contract revocation reason');
        }
    }

    /** Latest revision ONLY: an older accepted revision must never revive. */
    public function state(
        ?array $record,
        ?string $reviewDecision,
        bool $revoked,
        CarbonImmutable $now,
    ): string {
        if ($record === null) {
            return 'missing';
        }
        if ($reviewDecision === null) {
            return 'pending_review';
        }
        if ($reviewDecision === 'rejected') {
            return 'rejected';
        }
        if ($reviewDecision !== 'accepted') {
            return 'invalid_review';
        }
        if ($revoked) {
            return 'revoked';
        }
        $start = CarbonImmutable::parse((string) $record['valid_from']);
        $end = $record['valid_until'] === null
            ? null : CarbonImmutable::parse((string) $record['valid_until']);
        if ($start->greaterThan($now)) {
            return 'not_yet_valid';
        }
        if ($end !== null && $end->lessThanOrEqualTo($now)) {
            return 'expired';
        }
        return 'internally_accepted';
    }
}
