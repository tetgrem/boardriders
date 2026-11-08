<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** Pure casework rules. Archiving a case NEVER releases a carrier safety hold. */
final class CheckoutCodTtnInvestigationPolicy
{
    public const REASONS = [
        'assign' => ['initial_assignment', 'reassignment'],
        'escalate' => ['sla_breached', 'carrier_contact_needed', 'conflicting_evidence'],
        'reschedule' => ['carrier_response_pending', 'additional_review_needed'],
        'archive' => ['inconclusive_reviewed', 'comparison_documented', 'carrier_escalation_documented'],
    ];

    public function uuid(string $value): string
    {
        if (!\Illuminate\Support\Str::isUuid($value)) {
            throw new InvalidArgumentException('Invalid investigation UUID');
        }
        return strtolower($value);
    }

    public function deadline(DateTimeInterface $value, bool $requireFuture = true): string
    {
        $time = CarbonImmutable::instance($value)->utc();
        $now = CarbonImmutable::now('UTC');
        if ($requireFuture && ($time->lessThanOrEqualTo($now) || $time->greaterThan($now->addDays(30)))) {
            throw new InvalidArgumentException('Investigation deadline must be within the next 30 days');
        }
        return $time->format('Y-m-d H:i:s.uP');
    }

    public function event(string $type, string $reason, ?int $assigneeId, ?DateTimeInterface $deadline, bool $requireFuture = true): ?string
    {
        if (!isset(self::REASONS[$type]) || !in_array($reason, self::REASONS[$type], true)) {
            throw new InvalidArgumentException('Invalid investigation action/reason');
        }
        if ($type === 'assign' && ($assigneeId === null || $assigneeId <= 0 || $deadline !== null)) {
            throw new InvalidArgumentException('Assignment needs an assignee and no deadline');
        }
        if ($type === 'reschedule' && ($assigneeId !== null || $deadline === null)) {
            throw new InvalidArgumentException('Reschedule needs a deadline and no assignee');
        }
        if (in_array($type, ['escalate', 'archive'], true) && ($assigneeId !== null || $deadline !== null)) {
            throw new InvalidArgumentException('Unexpected investigation action attributes');
        }
        return $deadline === null ? null : $this->deadline($deadline, $requireFuture);
    }

    /**
     * @param list<array{event_type:string,assignee_id:?int,deadline_at:?string}> $events
     * @return array<string,mixed>
     */
    public function project(string $initialDeadline, array $events, CarbonImmutable $now): array
    {
        $deadline = CarbonImmutable::parse($initialDeadline)->utc();
        $assignee = null;
        $assigned = false;
        $escalated = false;
        $archived = false;
        foreach ($events as $event) {
            $type = $event['event_type'];
            if (!array_key_exists($type, self::REASONS)) {
                throw new InvalidArgumentException('Invalid investigation event history');
            }
            if ($archived) {
                throw new InvalidArgumentException('Investigation events after archival');
            }
            if ($type === 'assign') {
                $assigned = true;
                $assignee = $event['assignee_id'];
            } elseif ($type === 'reschedule') {
                $deadline = CarbonImmutable::parse((string) $event['deadline_at'])->utc();
            } elseif ($type === 'escalate') {
                $escalated = true;
            } elseif ($type === 'archive') {
                $archived = true;
            }
        }
        $state = $archived ? 'administratively_archived'
            : ($escalated ? 'escalated' : ($assigned ? 'in_progress' : 'awaiting_assignment'));
        return [
            'status' => $state,
            'assignee_id' => $assignee,
            'deadline_at' => $deadline->format('Y-m-d H:i:s.uP'),
            'overdue' => !$archived && $deadline->lessThanOrEqualTo($now),
            'archived' => $archived,
            'reconciliation_hold_released' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
