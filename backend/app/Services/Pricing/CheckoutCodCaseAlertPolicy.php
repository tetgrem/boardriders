<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** Time-only rules: no DB, side effects or payment confirmation. */
final class CheckoutCodCaseAlertPolicy
{
    /** @return list<array{kind:string,recipient_id:?int}> */
    public function alerts(
        string $status,
        ?int $assignedTo,
        DateTimeInterface|string $createdAt,
        DateTimeInterface|string $dueAt,
        DateTimeInterface $at
    ): array {
        if (!in_array($status, [
                'open',
                'in_progress',
                'waiting_external',
                'closed_internal_review',
            ], true) || ($assignedTo !== null && $assignedTo < 1)) {
            throw new InvalidArgumentException(
                'Invalid COD case alert facts'
            );
        }

        if ($status === 'closed_internal_review') {
            return [];
        }

        $created = CarbonImmutable::parse($createdAt)->utc();
        $due = CarbonImmutable::parse($dueAt)->utc();
        $now = CarbonImmutable::instance($at)->utc();
        $alerts = [];

        if ($status === 'open' && $assignedTo === null
            && $now->greaterThanOrEqualTo($created->addMinutes(30))) {
            $alerts[] = [
                'kind' => 'unassigned_30m',
                'recipient_id' => null,
            ];
        }

        if ($now->lessThan($due)
            && $now->greaterThanOrEqualTo($due->subHour())) {
            $alerts[] = [
                'kind' => 'due_soon',
                'recipient_id' => $assignedTo,
            ];
        }

        if ($now->greaterThanOrEqualTo($due)) {
            $alerts[] = [
                'kind' => 'overdue',
                'recipient_id' => $assignedTo,
            ];
        }

        if ($now->greaterThanOrEqualTo($due->addHours(4))) {
            $alerts[] = [
                'kind' => 'overdue_4h',
                'recipient_id' => null,
            ];
        }

        if ($now->greaterThanOrEqualTo($due->addHours(24))) {
            $alerts[] = [
                'kind' => 'overdue_24h',
                'recipient_id' => null,
            ];
        }

        return $alerts;
    }
}
