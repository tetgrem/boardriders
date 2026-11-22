<?php

declare(strict_types=1);

namespace App\Services\Fiscal63;

use DomainException;
use Illuminate\Support\Facades\DB;

final class Fiscal63ReportService
{
    public function __construct(
        private readonly Fiscal63Access $access,
        private readonly Fiscal63Integrity $integrity,
    ) {}

    public function list(
        int $limit = 20,
        ?int $beforeId = null,
        ?string $state = null
    ): array {
        $this->access->actor(
            Fiscal63Access::VIEW
        );

        if (
            $limit < 1
            || $limit > 100
            || (
                $beforeId !== null
                && $beforeId <= 0
            )
            || (
                $state !== null
                && !in_array(
                    $state,
                    [
                        'open',
                        'acknowledged',
                        'closed',
                    ],
                    true
                )
            )
        ) {
            throw new DomainException(
                'Invalid Fiscal63 case pagination'
            );
        }

        $query = DB::table(
            'checkout_fiscal_cases63 as c'
        );

        if ($beforeId !== null) {
            $query->where(
                'c.id',
                '<',
                $beforeId
            );
        }

        if ($state !== null) {
            if ($state === 'closed') {
                $query->whereExists(
                    fn ($q) =>
                    $q->selectRaw('1')
                        ->from(
                            'checkout_fiscal_case_actions63 as a'
                        )
                        ->whereColumn(
                            'a.case_id',
                            'c.id'
                        )
                        ->where('a.action', 'close')
                );

            } elseif ($state === 'acknowledged') {
                $query->whereExists(
                    fn ($q) =>
                    $q->selectRaw('1')
                        ->from(
                            'checkout_fiscal_case_actions63 as a'
                        )
                        ->whereColumn(
                            'a.case_id',
                            'c.id'
                        )
                        ->where('a.action', 'acknowledge')
                )
                    ->whereNotExists(
                        fn ($q) =>
                        $q->selectRaw('1')
                            ->from(
                                'checkout_fiscal_case_actions63 as a'
                            )
                            ->whereColumn(
                                'a.case_id',
                                'c.id'
                            )
                            ->where(
                                'a.action',
                                'close'
                            )
                    );

            } else {
                $query->whereNotExists(
                    fn ($q) =>
                    $q->selectRaw('1')
                        ->from(
                            'checkout_fiscal_case_actions63 as a'
                        )
                        ->whereColumn(
                            'a.case_id',
                            'c.id'
                        )
                        ->where(
                            'a.action',
                            'acknowledge'
                        )
                );
            }
        }

        $ids = $query
            ->orderByDesc('c.id')
            ->limit($limit + 1)
            ->pluck('c.id')
            ->all();

        $hasMore = count($ids) > $limit;

        $ids = array_slice(
            $ids,
            0,
            $limit
        );

        return [
            'items' => array_map(
                fn ($id) =>
                $this->integrity->read(
                    (int) $id
                ),
                $ids
            ),
            'next_before_id' =>
                $hasMore && $ids !== []
                    ? (int) end($ids)
                    : null,
        ];
    }

    public function dashboard(): array
    {
        $this->access->actor(
            Fiscal63Access::VIEW
        );

        $total = (int) DB::table(
            'checkout_fiscal_cases63'
        )->count();

        $ack = (int) DB::table(
            'checkout_fiscal_case_actions63'
        )
            ->where(
                'action',
                'acknowledge'
            )
            ->count();

        $closed = (int) DB::table(
            'checkout_fiscal_case_actions63'
        )
            ->where(
                'action',
                'close'
            )
            ->count();

        $recovered = (int) DB::table(
            'checkout_fiscal_case_actions63'
        )
            ->where(
                'action',
                'recover_uncertain'
            )
            ->count();

        $pollRequests = (int) DB::table(
            'checkout_fiscal_case_actions63'
        )
            ->where(
                'action',
                'poll_requested'
            )
            ->count();

        $reasons = DB::table(
            'checkout_fiscal_cases63'
        )
            ->select(
                'reason_code',
                DB::raw('COUNT(*) AS total')
            )
            ->groupBy('reason_code')
            ->get()
            ->mapWithKeys(
                fn ($row) => [
                    (string) $row->reason_code =>
                        (int) $row->total,
                ]
            )
            ->all();

        return [
            'total_cases' => $total,
            'open_cases' => $total - $ack,
            'acknowledged_cases' => $ack - $closed,
            'closed_cases' => $closed,
            'recovered_transmissions' => $recovered,
            'provider_get_requests_queued' =>
                $pollRequests,
            'by_reason' => $reasons,
            'provider_post_requests_sent' => 0,
            'refund_money_movements' => 0,
        ];
    }
}
