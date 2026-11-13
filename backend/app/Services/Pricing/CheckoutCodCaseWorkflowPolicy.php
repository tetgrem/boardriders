<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use InvalidArgumentException;

/** Pure FSM for COD operational cases; never updates payment status. */
final class CheckoutCodCaseWorkflowPolicy
{
    public const REASONS = [
        'claim' => ['start_work'],
        'request_evidence' => [
            'source_proof_needed',
            'shipment_proof_needed',
            'manual_recheck',
        ],
        'resume' => ['evidence_received', 'manual_recheck'],
        'release' => ['reassign_required'],
        'close' => ['comparison_only'],
        'refresh' => ['evidence_changed'],
    ];

    public function slaHours(string $priority): int
    {
        return match ($priority) {
            'critical' => 4,
            'normal' => 24,
            'low' => 72,
            default => throw new InvalidArgumentException(
                'Unknown COD case priority'
            ),
        };
    }

    public function assertReason(string $action, string $reason): void
    {
        if (!isset(self::REASONS[$action])
            || !in_array($reason, self::REASONS[$action], true)) {
            throw new InvalidArgumentException(
                'Invalid COD case action/reason'
            );
        }
    }

    public function next(
        string $status,
        string $action,
        bool $isOwner,
        bool $evidenceChanged,
        bool $cleanComparison,
        bool $independentlyReviewed
    ): string {
        if ($action === 'refresh') {
            if (!$evidenceChanged) {
                throw new DomainException(
                    'COD case evidence has not changed'
                );
            }

            return 'open';
        }

        if ($evidenceChanged) {
            throw new DomainException(
                'COD case evidence changed; refresh required'
            );
        }

        return match ($action) {
            'claim' => $status === 'open'
                ? 'in_progress'
                : throw new DomainException(
                    'COD case is not available for claim'
                ),

            'request_evidence' => $status === 'in_progress'
            && $isOwner
                ? 'waiting_external'
                : throw new DomainException(
                    'Claim the COD case before requesting evidence'
                ),

            'resume' => $status === 'waiting_external'
            && $isOwner
                ? 'in_progress'
                : throw new DomainException(
                    'COD case cannot be resumed'
                ),

            'release' => in_array(
                $status,
                ['in_progress', 'waiting_external'],
                true
            ) && $isOwner
                ? 'open'
                : throw new DomainException(
                    'Only the assigned COD agent can release a case'
                ),

            'close' => $status === 'in_progress'
            && $isOwner
            && $cleanComparison
            && $independentlyReviewed
                ? 'closed_internal_review'
                : throw new DomainException(
                    'Independent clean comparison required to close internal review'
                ),

            default => throw new InvalidArgumentException(
                'Unknown COD case action'
            ),
        };
    }
}
