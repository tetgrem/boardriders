<?php

declare(strict_types=1);

namespace App\Services\Fiscal63;

use Carbon\CarbonImmutable;

final class Fiscal63Policy
{
    public function reason(object $ticket): ?string
    {
        $status = (string) $ticket->status;

        if ($status === 'needs_review') {
            return 'provider_needs_review';
        }

        if (
            $status === 'transmitting'
            && $this->older(
                $ticket->attempted_at,
                'transmitting_minutes'
            )
        ) {
            return 'stale_transmission';
        }

        if (
            $status === 'uncertain'
            && $this->older(
                $ticket->attempted_at,
                'uncertain_minutes'
            )
        ) {
            return 'uncertain_submission';
        }

        if (
            $status === 'provider_pending'
            && $this->older(
                $ticket->attempted_at,
                'pending_minutes'
            )
        ) {
            return 'pending_too_long';
        }

        if (
            $status === 'prepared'
            && $this->older(
                $ticket->created_at,
                'prepared_minutes'
            )
        ) {
            return 'prepared_backlog';
        }

        return null;
    }

    public function transmissionIsStale(object $ticket): bool
    {
        return $ticket->status === 'transmitting'
            && $this->older(
                $ticket->attempted_at,
                'transmitting_minutes'
            );
    }

    private function older(
        mixed $date,
        string $configKey
    ): bool {
        if ($date === null) {
            return false;
        }

        $minutes = (int) config(
            'fiscal63.' . $configKey
        );

        return $minutes > 0
            && CarbonImmutable::parse(
                (string) $date
            )->lte(
                CarbonImmutable::now('UTC')
                    ->subMinutes($minutes)
            );
    }
}
