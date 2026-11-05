<?php

namespace App\Services\Inventory;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryIntegrityService
{
    /**
     * Знаходить розбіжності між загальним резервом
     * і сумою активних іменних резервувань.
     */
    public function findMismatches(
        int $limit = 50,
        ?int $stockId = null
    ): Collection {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException(
                'Ліміт має бути від 1 до 1000.'
            );
        }

        /*
         * Підраховуємо активний резерв
         * для кожного складського залишку.
         */
        $reservationTotals = DB::table(
            'inventory_reservations'
        )
            ->select('inventory_stock_id')
            ->selectRaw(
                'SUM(quantity - shipped_qty - released_qty)
                 AS expected_qty'
            )
            ->groupBy('inventory_stock_id');

        /*
         * Порівнюємо результат із reserved_qty.
         *
         * LEFT JOIN дозволяє знаходити залишки,
         * для яких взагалі немає резервувань.
         */
        return DB::table('inventory_stocks as s')
            ->leftJoinSub(
                $reservationTotals,
                'r',
                function (JoinClause $join) {
                    $join->on(
                        's.id',
                        '=',
                        'r.inventory_stock_id'
                    );
                }
            )
            ->when(
                $stockId !== null,
                fn ($query) => $query->where(
                    's.id',
                    $stockId
                )
            )
            ->whereRaw(
                's.reserved_qty <> COALESCE(r.expected_qty, 0)'
            )
            ->select(
                's.id',
                's.warehouse_id',
                's.variant_id',
                's.reserved_qty'
            )
            ->selectRaw(
                'COALESCE(r.expected_qty, 0)
                 AS expected_qty'
            )
            ->orderBy('s.id')
            ->limit($limit)
            ->get();
    }
}
