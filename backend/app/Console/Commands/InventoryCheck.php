<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryIntegrityService;
use Illuminate\Console\Command;

class InventoryCheck extends Command
{
    protected $signature = 'inventory:check
        {--limit=50 : Максимальна кількість розбіжностей}';

    protected $description =
        'Перевіряє відповідність складських резервів';

    public function handle(
        InventoryIntegrityService $service
    ): int {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 1000) {
            $this->error(
                'Параметр limit має бути від 1 до 1000.'
            );

            return self::FAILURE;
        }

        $this->info(
            'Перевірка складських резервувань...'
        );

        $mismatches = $service->findMismatches($limit);

        if ($mismatches->isEmpty()) {
            $this->info(
                'Розбіжностей не виявлено.'
            );

            return self::SUCCESS;
        }

        $rows = $mismatches->map(
            fn ($stock) => [
                $stock->id,
                $stock->warehouse_id,
                $stock->variant_id,
                $stock->reserved_qty,
                $stock->expected_qty,
            ]
        )->all();

        $this->table(
            [
                'Stock ID',
                'Warehouse ID',
                'Variant ID',
                'Reserved',
                'Expected',
            ],
            $rows
        );

        $this->error(
            'Виявлено розбіжності складських резервів!'
        );

        if ($mismatches->count() >= $limit) {
            $this->warn(
                'Досягнуто ліміту виведення. '
                . 'Можуть існувати інші розбіжності.'
            );
        }

        return self::FAILURE;
    }
}
