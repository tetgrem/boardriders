<?php

declare(strict_types=1);

namespace App\Services\Fiscal63;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class Fiscal63MonitorService
{
    public function __construct(
        private readonly Fiscal63Access $access,
        private readonly Fiscal63CaseService $cases,
    ) {}

    public function scan(
        int $limit = 100
    ): array {
        return $this->scanAs(
            $this->access->actor(
                Fiscal63Access::MONITOR
            ),
            $limit
        );
    }

    public function scanSystem(
        int $limit = 100
    ): array {
        $actor = config(
            'fiscal63.system_actor_id'
        );

        if (
            !is_numeric($actor)
            || (int) $actor <= 0
        ) {
            throw new DomainException(
                'Fiscal63 system actor is not configured'
            );
        }

        return $this->scanAs(
            (int) $actor,
            $limit
        );
    }

    private function scanAs(
        int $actor,
        int $limit
    ): array {
        if (
            config('fiscal63.enabled') !== true
        ) {
            throw new DomainException(
                'Fiscal63 monitoring disabled'
            );
        }

        if (
            $limit < 1
            || $limit > 500
        ) {
            throw new DomainException(
                'Fiscal63 scan limit must be 1..500'
            );
        }

        $now = CarbonImmutable::now('UTC');

        $conditions = [
            [
                'needs_review',
                'provider_needs_review',
                null,
                null,
            ],
            [
                'transmitting',
                'stale_transmission',
                'attempted_at',
                $now->subMinutes(
                    (int) config(
                        'fiscal63.transmitting_minutes'
                    )
                ),
            ],
            [
                'uncertain',
                'uncertain_submission',
                'attempted_at',
                $now->subMinutes(
                    (int) config(
                        'fiscal63.uncertain_minutes'
                    )
                ),
            ],
            [
                'provider_pending',
                'pending_too_long',
                'attempted_at',
                $now->subMinutes(
                    (int) config(
                        'fiscal63.pending_minutes'
                    )
                ),
            ],
            [
                'prepared',
                'prepared_backlog',
                'created_at',
                $now->subMinutes(
                    (int) config(
                        'fiscal63.prepared_minutes'
                    )
                ),
            ],
        ];

        $query = DB::table(
            'checkout_fiscal_tickets62 as t'
        )
            ->select('t.id')
            ->where(function ($where) use (
                $conditions
            ): void {
                foreach ($conditions as [
                         $status,
                         $reason,
                         $dateField,
                         $cutoff,
                ]) {
                    $where->orWhere(
                        function ($branch) use (
                            $status,
                            $reason,
                            $dateField,
                            $cutoff
                        ): void {
                            $branch->where(
                                't.status',
                                $status
                            );

                            if ($dateField !== null) {
                                $branch->where(
                                    't.' . $dateField,
                                    '<=',
                                    $cutoff
                                );
                            }

                            $branch->whereNotExists(
                                function ($sub) use (
                                    $reason
                                ): void {
                                    $sub->selectRaw('1')
                                        ->from(
                                            'checkout_fiscal_cases63 as c'
                                        )
                                        ->whereColumn(
                                            'c.ticket_id',
                                            't.id'
                                        )
                                        ->where(
                                            'c.reason_code',
                                            $reason
                                        );
                                }
                            );
                        }
                    );
                }
            })
            ->orderBy('t.id')
            ->limit($limit);

        $ids = $query->pluck('id');

        $opened = [];

        foreach ($ids as $id) {
            $case = $this->cases->openDetected(
                (int) $id,
                $actor
            );

            if ($case !== null) {
                $opened[] = [
                    'case_id' => $case['case_id'],
                    'ticket_id' => $case['ticket_id'],
                    'reason_code' => $case['reason_code'],
                ];
            }
        }

        return [
            'scanned_candidates' => $ids->count(),
            'cases_opened_or_confirmed' =>
                count($opened),
            'cases' => $opened,
            'external_requests_sent' => 0,
        ];
    }
}
