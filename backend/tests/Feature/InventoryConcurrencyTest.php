<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    private int $warehouseId = 0;
    private int $productId = 0;
    private int $variantId = 0;
    private int $stockId = 0;

    private array $workerOutputs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Жодних тестів в основній базі.
        if (
            config('database.default') !== 'pgsql' ||
            DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Only boardriders_test is allowed.'
            );
        }

        $now = now();
        $code = (string) Str::uuid();

        $this->warehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'RACE-' . $code,
                'name' => 'Тестовий склад',
                'kind' => 'owned',
                'country_code' => 'UA',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->productId = DB::table('products')
            ->insertGetId([
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->variantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $this->productId,
                'sku' => 'RACE-' . $code,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // Фізично лише одна одиниця.
        $this->stockId = DB::table('inventory_stocks')
            ->insertGetId([
                'warehouse_id' => $this->warehouseId,
                'variant_id' => $this->variantId,
                'on_hand_qty' => 1,
                'reserved_qty' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /*
     * Два покупці намагаються зарезервувати
     * останню одиницю товару.
     */
    public function test_only_one_buyer_gets_last_item(): void
    {
        $results = $this->runRace(
            (string) Str::uuid(),
            (string) Str::uuid()
        );

        sort($results);

        // Один успіх, одна відмова.
        $this->assertSame(
            [0, 2],
            $results,
            implode("\n", $this->workerOutputs)
        );

        $this->assertStockAndReservation(1);
    }

    /*
     * Два одночасні запити з однаковим UUID.
     * Має бути один резерв, а не два.
     */
    public function test_same_key_does_not_duplicate_reservation(): void
    {
        $key = (string) Str::uuid();

        $results = $this->runRace($key, $key);

        // Обидва отримують успішну відповідь.
        $this->assertSame(
            [0, 0],
            $results,
            implode("\n", $this->workerOutputs)
        );

        // Але резервування створено лише одне.
        $this->assertStockAndReservation(1);
    }

    private function runRace(
        string $firstKey,
        string $secondKey
    ): array {
        $first = $this->makeWorker($firstKey);
        $second = $this->makeWorker($secondKey);

        /*
         * Блокуємо останню одиницю.
         * Обидва процеси будуть змушені
         * чекати звільнення блокування.
         */
        DB::beginTransaction();

        try {
            DB::table('inventory_stocks')
                ->where('id', $this->stockId)
                ->lockForUpdate()
                ->first();

            $first->start();
            $second->start();

            // Чекаємо готовності обох PHP-процесів.
            $deadline = microtime(true) + 10;

            while (
                !str_contains($first->getOutput(), 'READY') ||
                !str_contains($second->getOutput(), 'READY')
            ) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException(
                        "Workers did not start:\n"
                        . $first->getErrorOutput()
                        . $second->getErrorOutput()
                    );
                }

                usleep(50000);
            }
        } finally {
            // Звільняємо блокування рядка.
            DB::rollBack();
        }

        // Чекаємо завершення обох процесів.
        $first->wait();
        $second->wait();

        $this->workerOutputs = [
            $first->getOutput() . $first->getErrorOutput(),
            $second->getOutput() . $second->getErrorOutput(),
        ];

        return [
            $first->getExitCode(),
            $second->getExitCode(),
        ];
    }

    private function makeWorker(string $key): Process
    {
        $process = new Process(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/inventory-reserve-worker.php'
                ),
                (string) $this->warehouseId,
                (string) $this->variantId,
                '1',
                'ORDER-RACE-001',
                $key,
            ],
            base_path(),
            ['APP_ENV' => 'testing']
        );

        $process->setTimeout(20);

        return $process;
    }

    private function assertStockAndReservation(
        int $expected
    ): void {
        $stock = DB::table('inventory_stocks')
            ->where('id', $this->stockId)
            ->first();

        $this->assertEquals(1, $stock->on_hand_qty);
        $this->assertEquals($expected, $stock->reserved_qty);

        $this->assertEquals(
            1,
            DB::table('inventory_reservations')
                ->where('inventory_stock_id', $this->stockId)
                ->count()
        );
    }

    protected function tearDown(): void
    {
        try {
            // Прибираємо лише дані цього тесту.
            if ($this->stockId) {
                $reservationIds = DB::table('inventory_reservations')
                    ->where('inventory_stock_id', $this->stockId)
                    ->pluck('id');

                DB::table('inventory_reservation_operations')
                    ->whereIn('inventory_reservation_id', $reservationIds)
                    ->delete();

                DB::table('inventory_reservations')
                    ->where('inventory_stock_id', $this->stockId)
                    ->delete();

                DB::table('inventory_movements')
                    ->where('inventory_stock_id', $this->stockId)
                    ->delete();

                DB::table('inventory_stocks')
                    ->where('id', $this->stockId)
                    ->delete();
            }

            if ($this->variantId) {
                DB::table('product_variants')
                    ->where('id', $this->variantId)
                    ->delete();
            }

            if ($this->productId) {
                DB::table('products')
                    ->where('id', $this->productId)
                    ->delete();
            }

            if ($this->warehouseId) {
                DB::table('warehouses')
                    ->where('id', $this->warehouseId)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
