<?php

namespace App\Console\Commands;

use App\Models\InventoryReservation;
use Illuminate\Console\Command;

class InventoryExpire extends Command
{
    protected $signature = 'inventory:expire
        {--limit=50 : Максимальна кількість записів}';

    protected $description =
        'Показує прострочені активні резервування без змін у БД';

    public function handle(): int
    {
        $rawLimit = (string) $this->option('limit');

        if (
            !ctype_digit($rawLimit) ||
            (int) $rawLimit < 1 ||
            (int) $rawLimit > 500
        ) {
            $this->error(
                'Ліміт має бути цілим числом від 1 до 500.'
            );

            return self::FAILURE;
        }

        $limit = (int) $rawLimit;

        $this->info(
            'Режим перегляду: дані не змінюються.'
        );

        $reservations = InventoryReservation::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereRaw(
                '(quantity - shipped_qty - released_qty) > 0'
            )
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get([
                'id',
                'reservation_key',
                'reference',
                'inventory_stock_id',
                'quantity',
                'shipped_qty',
                'released_qty',
                'expires_at',
            ]);

        $hasMore = $reservations->count() > $limit;

        $reservations = $reservations->take($limit);

        if ($reservations->isEmpty()) {
            $this->info(
                'Активних прострочених резервувань не знайдено.'
            );

            return self::SUCCESS;
        }

        $rows = $reservations->map(function ($reservation) {
            return [
                $reservation->id,
                $reservation->reference,
                $reservation->inventory_stock_id,
                $reservation->active_qty,
                $reservation->expires_at->format(
                    'Y-m-d H:i:s'
                ),
            ];
        })->all();

        $this->table(
            [
                'ID',
                'Замовлення',
                'Stock ID',
                'Активний резерв',
                'Термін завершення',
            ],
            $rows
        );

        $this->warn(
            'Знайдено записів: ' . $reservations->count()
        );

        if ($hasMore) {
            $this->warn(
                'Є додаткові записи. Збільште --limit.'
            );
        }

        $this->comment(
            'Резервування не змінено.'
        );

        return self::SUCCESS;
    }
}
