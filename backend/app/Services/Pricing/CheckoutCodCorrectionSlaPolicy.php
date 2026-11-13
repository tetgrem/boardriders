<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CheckoutCodCorrectionSlaPolicy
{
    public function __construct(
        private int $reviewMinutes = 240,
        private int $publicationMinutes = 120,
        private int $rejectionMinutes = 1440,
        private int $warningMinutes = 60,
        private int $managementMinutes = 240,
        private int $criticalMinutes = 1440,
    ) {
        foreach ([
                     $reviewMinutes, $publicationMinutes, $rejectionMinutes,
                     $warningMinutes, $managementMinutes, $criticalMinutes,
                 ] as $minutes) {
            if ($minutes < 1 || $minutes > 525600) {
                throw new InvalidArgumentException('Invalid COD correction SLA configuration');
            }
        }

        if ($managementMinutes >= $criticalMinutes) {
            throw new InvalidArgumentException(
                'Management escalation must precede critical escalation'
            );
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('cod_correction_sla.review_minutes', 240),
            (int) config('cod_correction_sla.publication_minutes', 120),
            (int) config('cod_correction_sla.rejection_followup_minutes', 1440),
            (int) config('cod_correction_sla.warning_minutes', 60),
            (int) config('cod_correction_sla.management_escalation_minutes', 240),
            (int) config('cod_correction_sla.critical_escalation_minutes', 1440),
        );
    }

    /**
     * @return array{
     *   phase:string,
     *   stage:string,
     *   severity:string,
     *   target_role:string,
     *   due_at:CarbonImmutable
     * }|null
     */
    public function evaluate(
        string $phase,
        CarbonImmutable $startedAt,
        CarbonImmutable $asOf
    ): ?array {
        $minutes = match ($phase) {
            'review' => $this->reviewMinutes,
            'publication' => $this->publicationMinutes,
            'rejection' => $this->rejectionMinutes,
            default => throw new InvalidArgumentException(
                'Invalid COD correction SLA phase'
            ),
        };

        $due = $startedAt->utc()->addMinutes($minutes);
        $now = $asOf->utc();

        if ($phase === 'rejection' && $now->lessThan($due)) {
            return $this->result(
                $phase, 'rejected_action', 'critical',
                'finance_management', $due
            );
        }

        if ($phase !== 'rejection'
            && $now->lessThan($due->subMinutes($this->warningMinutes))) {
            return null;
        }

        if ($phase !== 'rejection' && $now->lessThan($due)) {
            return $this->result(
                $phase, 'due_soon', 'warning',
                $phase === 'review' ? 'finance_review' : 'finance_publication',
                $due
            );
        }

        if ($now->greaterThanOrEqualTo(
            $due->addMinutes($this->criticalMinutes)
        )) {
            return $this->result(
                $phase, 'overdue_24h', 'critical',
                'finance_management', $due
            );
        }

        if ($now->greaterThanOrEqualTo(
            $due->addMinutes($this->managementMinutes)
        )) {
            return $this->result(
                $phase, 'overdue_4h', 'critical',
                'finance_management', $due
            );
        }

        return $this->result(
            $phase, 'overdue', 'high',
            $phase === 'review' ? 'finance_review'
                : ($phase === 'publication'
                ? 'finance_publication'
                : 'finance_management'),
            $due
        );
    }

    private function result(
        string $phase,
        string $stage,
        string $severity,
        string $targetRole,
        CarbonImmutable $due
    ): array {
        return [
            'phase' => $phase,
            'stage' => $stage,
            'severity' => $severity,
            'target_role' => $targetRole,
            'due_at' => $due,
        ];
    }
}
