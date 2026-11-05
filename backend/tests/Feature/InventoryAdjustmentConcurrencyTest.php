<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryAdjustmentConcurrencyTest extends TestCase
{
    private InventoryService $inventory;

    private int $warehouseId = 0;
    private int $productId = 0;
    private int $variantId = 0;
    private int $stockId = 0;

    private string $receiptReference = '';
    private ?string $gateFile = null;

    /** @var Process[] */
    private array $workers = [];

    private array $adjustmentKeys = [];
    private array $workerOutputs = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql' ||
            DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Only boardriders_test is allowed.'
            );
        }

        $this->inventory = app(InventoryService::class);

        $now = now();
        $suffix = (string) Str::uuid();

        $this->warehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'ADJ-RACE-' . $suffix,
                'name' => 'Adjustment concurrency warehouse',
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
                'sku' => 'ADJ-RACE-SKU-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->receiptReference =
            'ADJ-RACE-RECEIPT-' . $suffix;

        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            $this->receiptReference,
            (string) \Illuminate\Support\Str::uuid()
        );

        $this->stockId = $stock->id;
    }

    /**
     * Две разные инвентаризации отправлены
     * с одной исходной версией.
     *
     * Только одна должна быть применена.
     */
    public function test_only_one_adjustment_accepts_same_revision(): void
    {
        $revision = $this->currentRevision();

        $results = $this->runRace(
            [
                7,
                'RACE-STOCKTAKE-001-A',
                (string) Str::uuid(),
            ],
            [
                8,
                'RACE-STOCKTAKE-001-B',
                (string) Str::uuid(),
            ],
            $revision
        );

        sort($results);

        // 0 = успех, 2 = устаревшая версия.
        $this->assertSame(
            [0, 2],
            $results,
            implode("\n", $this->workerOutputs)
        );

        $this->assertTrue(
            collect($this->workerOutputs)->contains(
                fn ($output) => str_contains(
                    $output,
                    'застаріли'
                )
            ),
            implode("\n", $this->workerOutputs)
        );

        $stock = DB::table('inventory_stocks')
            ->where('id', $this->stockId)
            ->first();

        // Победителем мог стать любой процесс.
        $this->assertContains(
            (int) $stock->on_hand_qty,
            [7, 8]
        );

        $this->assertEquals(
            $revision + 1,
            $stock->revision
        );

        $this->assertEquals(0, $stock->reserved_qty);

        // Только одна корректировка и одно движение.
        $this->assertSame(1, $this->adjustmentCount());
        $this->assertSame(1, $this->adjustmentMovementCount());
    }

    /**
     * Два одинаковых запроса с одним UUID.
     *
     * Оба получают успешный ответ,
     * но документ создаётся только один.
     */
    public function test_simultaneous_duplicate_adjustment_is_idempotent(): void
    {
        $revision = $this->currentRevision();
        $key = (string) Str::uuid();

        $request = [
            6,
            'RACE-STOCKTAKE-002',
            $key,
        ];

        $results = $this->runRace(
            $request,
            $request,
            $revision
        );

        $this->assertSame(
            [0, 0],
            $results,
            implode("\n", $this->workerOutputs)
        );

        $stock = DB::table('inventory_stocks')
            ->where('id', $this->stockId)
            ->first();

        $this->assertEquals(6, $stock->on_hand_qty);
        $this->assertEquals(0, $stock->reserved_qty);

        $this->assertEquals(
            $revision + 1,
            $stock->revision
        );

        $this->assertSame(1, $this->adjustmentCount());
        $this->assertSame(1, $this->adjustmentMovementCount());

        $this->assertDatabaseHas('inventory_adjustments', [
            'adjustment_key' => $key,
            'inventory_stock_id' => $this->stockId,
            'previous_qty' => 10,
            'counted_qty' => 6,
            'quantity_delta' => -4,
            'expected_revision' => $revision,
        ]);
    }

    private function currentRevision(): int
    {
        return (int) DB::table('inventory_stocks')
            ->where('id', $this->stockId)
            ->value('revision');
    }

    /**
     * Запускаем независимые PHP-процессы.
     */
    private function runRace(
        array $first,
        array $second,
        int $revision
    ): array {
        $this->gateFile = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'boardriders-adjustment-'
            . Str::uuid()
            . '.go';

        foreach ([$first, $second] as $request) {
            [$countedQty, $reference, $key] = $request;

            $this->adjustmentKeys[] = $key;

            $process = new Process(
                [
                    PHP_BINARY,
                    base_path(
                        'tests/Support/inventory-adjustment-worker.php'
                    ),
                    (string) $this->warehouseId,
                    (string) $this->variantId,
                    (string) $countedQty,
                    $reference,
                    $key,
                    (string) $revision,
                    $this->gateFile,
                ],
                base_path(),
                ['APP_ENV' => 'testing']
            );

            $process->setTimeout(30);

            $this->workers[] = $process;
        }

        // Блокируем строку склада до запуска
        // обеих конкурентных операций.
        DB::beginTransaction();

        try {
            DB::table('inventory_stocks')
                ->where('id', $this->stockId)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($this->workers as $worker) {
                $worker->start();
            }

            $deadline = microtime(true) + 15;

            while (
                !str_contains($this->workers[0]->getOutput(), 'READY')
                ||
                !str_contains($this->workers[1]->getOutput(), 'READY')
            ) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException(
                        'Workers failed to start: '
                        . $this->workers[0]->getErrorOutput()
                        . $this->workers[1]->getErrorOutput()
                    );
                }

                usleep(20000);
            }

            // Одновременно разрешаем обоим процессам
            // перейти к корректировке.
            if (
                file_put_contents(
                    $this->gateFile,
                    'GO'
                ) === false
            ) {
                throw new RuntimeException(
                    'Cannot create start gate.'
                );
            }

            // Даём процессам время дойти до БД.
            usleep(300000);
        } finally {
            // Освобождаем блокировку.
            DB::rollBack();
        }

        foreach ($this->workers as $worker) {
            $worker->wait();

            $this->workerOutputs[] =
                $worker->getOutput()
                . $worker->getErrorOutput();
        }

        return array_map(
            fn (Process $worker) => $worker->getExitCode(),
            $this->workers
        );
    }

    private function adjustmentCount(): int
    {
        return DB::table('inventory_adjustments')
            ->where('inventory_stock_id', $this->stockId)
            ->count();
    }

    private function adjustmentMovementCount(): int
    {
        $references = array_map(
            fn ($key) => 'ADJ-' . $key,
            array_unique($this->adjustmentKeys)
        );

        return DB::table('inventory_movements')
            ->whereIn('reference', $references)
            ->count();
    }

    /**
     * Удаляем только данные текущего теста.
     *
     * DatabaseTransactions не используем:
     * дочерние процессы должны видеть записи.
     */
    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }

            if (
                $this->gateFile !== null
                && is_file($this->gateFile)
            ) {
                unlink($this->gateFile);
            }

            if ($this->stockId > 0) {
                $movementReferences = [
                    $this->receiptReference,
                ];

                foreach ($this->adjustmentKeys as $key) {
                    $movementReferences[] = 'ADJ-' . $key;
                }

                DB::table('inventory_movements')
                    ->whereIn(
                        'reference',
                        array_unique($movementReferences)
                    )
                    ->delete();

                DB::table('inventory_adjustments')
                    ->where('inventory_stock_id', $this->stockId)
                    ->delete();


                DB::table('inventory_movements')
                    ->where('inventory_stock_id', $this->stockId)
                    ->delete();

                DB::table('inventory_receipts')
                    ->where('inventory_stock_id', $this->stockId)
                    ->delete();


                DB::table('inventory_stocks')
                    ->where('id', $this->stockId)
                    ->delete();
            }

            if ($this->variantId > 0) {
                DB::table('product_variants')
                    ->where('id', $this->variantId)
                    ->delete();
            }

            if ($this->productId > 0) {
                DB::table('products')
                    ->where('id', $this->productId)
                    ->delete();
            }

            if ($this->warehouseId > 0) {
                DB::table('warehouses')
                    ->where('id', $this->warehouseId)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
