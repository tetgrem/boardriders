<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use DomainException;
use Illuminate\Support\Facades\DB;

final class Fiscal62ReportService
{
    public function __construct(
        private readonly Fiscal62Access $access,
        private readonly Fiscal62Integrity $audit,
    ) {}

    public function ticket(int $ticketId): array
    {
        $this->access->actor(
            Fiscal62Access::VIEW
        );

        $ticket = $this->audit->ticket($ticketId);

        return [
            ...$ticket,
            'events' => $this->audit->events($ticketId),
            'real_fiscal_receipt_confirmed' =>
                $ticket['status'] === 'done',
        ];
    }

    public function queue(
        int $limit = 20,
        ?int $beforeId = null
    ): array {
        $this->access->actor(
            Fiscal62Access::VIEW
        );

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId <= 0)
        ) {
            throw new DomainException(
                'Invalid Fiscal62 queue pagination'
            );
        }

        $query = DB::table(
            'checkout_fiscal_tickets62'
        )
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $ids = $query->pluck('id')
            ->map(
                fn ($id): int => (int) $id
            )
            ->all();

        $next = count($ids) > $limit
            ? (int) $ids[$limit - 1]
            : null;

        $ids = array_slice(
            $ids,
            0,
            $limit
        );

        return [
            'items' => array_map(
                fn (int $id): array =>
                $this->audit->ticket($id),
                $ids
            ),
            'next_before_id' => $next,
        ];
    }

    public function stats(): array
    {
        $this->access->actor(
            Fiscal62Access::VIEW
        );

        $rows = DB::table(
            'checkout_fiscal_tickets62'
        )
            ->select(
                'status',
                DB::raw('COUNT(*) AS total')
            )
            ->groupBy('status')
            ->get();

        $counts = [
            'prepared' => 0,
            'transmitting' => 0,
            'uncertain' => 0,
            'provider_pending' => 0,
            'needs_review' => 0,
            'done' => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row->status] =
                (int) $row->total;
        }

        return [
            'counts' => $counts,
            'live_enabled' =>
                config('fiscal62.live_enabled') === true,
            'production_contract_certified' =>
                config('fiscal62.receipt_contract_certified') === true,
        ];
    }
}
