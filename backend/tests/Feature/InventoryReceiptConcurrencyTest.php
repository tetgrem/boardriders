<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryReceiptConcurrencyTest extends TestCase
{
    private int $warehouseId = 0;
    private int $productId = 0;
    private int $variantId = 0;
    private int $stockId = 0;

    private ?string $gateFile = null;

    /** @var Process[] */
    private array $workers = [];

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

        $now = now();
        $suffix = (string) Str::uuid();

        $this->warehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'RCV-RACE-' . $suffix,
                'name' => 'Receipt concurrency warehouse',
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
                'sku' => 'RCV-RACE-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // Создаём начальный остаток — 10 единиц.
        $stock = app(InventoryService::class)->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'INITIAL-RECEIPT-' . $suffix,
            (string) Str::uuid()
        );

        $this->stockId = (int) $stock->id;
    }

    /**
     * 1. Два одинаковых запроса одновременно.
     * Товар должен поступить только один раз.
     */
    public function test_simultaneous_duplicate_receipt_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $revision = $this->revision();

        $request = [
            7,
            'RACE-RECEIPT-001',
            $key,
        ];

        $results = $this->runRace(
            $request,
            $request
        );

        $this->assertSame(
            [0, 0],
            $results,
            implode("\n", $this->workerOutputs)
        );

        // Было 10. Добавили 7 только один раз.
        $this->assertStock(17);

        $this->assertSame(1, $this->receiptCount($key));
        $this->assertSame(1, $this->movementCount($key));

        $this->assertSame(
            $revision + 1,
            $this->revision()
        );
    }

    /**
     * 2. Один UUID, но разные количества.
     * Первый успешный запрос побеждает,
     * второй должен быть отклонён.
     */
    public function test_simultaneous_conflicting_receipts_are_rejected(): void
    {
        $key = (string) Str::uuid();
        $revision = $this->revision();

        $results = $this->runRace(
            [4, 'RACE-RECEIPT-002', $key],
            [9, 'RACE-RECEIPT-002', $key]
        );

        sort($results);

        // 0 = успех; 2 = конфликт UUID.
        $this->assertSame(
            [0, 2],
            $results,
            implode("\n", $this->workerOutputs)
        );

        $this->assertTrue(
            collect($this->workerOutputs)->contains(
                fn ($output) => str_contains(
                    $output,
                    'UUID приймання вже використано'
                )
            ),
            implode("\n", $this->workerOutputs)
        );

        $quantity = (int) DB::table('inventory_stocks')
            ->where('id', $this->stockId)
            ->value('on_hand_qty');

        // Победителем может быть любой процесс.
        $this->assertContains($quantity, [14, 19]);

        $this->assertSame(1, $this->receiptCount($key));
        $this->assertSame(1, $this->movementCount($key));

        $this->assertSame(
            $revision + 1,
            $this->revision()
        );
    }

    /**
     * 3. Два разных UUID.
     * Обе независимые приёмки должны выполниться.
     */
    public function test_simultaneous_different_receipts_are_both_applied(): void
    {
        $firstKey = (string) Str::uuid();
        $secondKey = (string) Str::uuid();

        $revision = $this->revision();

        $results = $this->runRace(
            [3, 'RACE-RECEIPT-003-A', $firstKey],
            [5, 'RACE-RECEIPT-003-B', $secondKey]
        );

        $this->assertSame(
            [0, 0],
            $results,
            implode("\n", $this->workerOutputs)
        );

        // 10 + 3 + 5 = 18.
        $this->assertStock(18);

        $this->assertSame(1, $this->receiptCount($firstKey));
        $this->assertSame(1, $this->receiptCount($secondKey));

        $this->assertSame(1, $this->movementCount($firstKey));
        $this->assertSame(1, $this->movementCount($secondKey));

        $this->assertSame(
            $revision + 2,
            $this->revision()
        );
    }

    /**
     * Запускаем два независимых PHP-процесса.
     */
    private function runRace(
        array $first,
        array $second
    ): array {
        $this->gateFile = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'boardriders-receipt-'
            . Str::uuid()
            . '.go';

        $this->workers = [];
        $this->workerOutputs = [];

        foreach ([$first, $second] as $request) {
            [$quantity, $reference, $key] = $request;

            $worker = new Process(
                [
                    PHP_BINARY,
                    base_path(
                        'tests/Support/inventory-receipt-worker.php'
                    ),
                    (string) $this->warehouseId,
                    (string) $this->variantId,
                    (string) $quantity,
                    $reference,
                    $key,
                    $this->gateFile,
                ],
                base_path(),
                ['APP_ENV' => 'testing']
            );

            $worker->setTimeout(30);

            $this->workers[] = $worker;
        }

        // Временно блокируем общий складской остаток.
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
                !str_contains(
                    $this->workers[0]->getOutput(),
                    'READY'
                )
                ||
                !str_contains(
                    $this->workers[1]->getOutput(),
                    'READY'
                )
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

            // Одновременно разрешаем обеим приёмкам
            // обращаться к сервису.
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

            usleep(300000);

        } finally {
            // Освобождаем блокировку.
            DB::rollBack();
        }

        $results = [];

        foreach ($this->workers as $worker) {
            $worker->wait();

            $results[] = $worker->getExitCode();

            $this->workerOutputs[] =
                $worker->getOutput()
                . $worker->getErrorOutput();
        }

        return $results;
    }

    private function assertStock(int $quantity): void
    {
        $this->assertDatabaseHas('inventory_stocks', [
            'id' => $this->stockId,
            'on_hand_qty' => $quantity,
            'reserved_qty' => 0,
        ]);
    }

    private function revision(): int
    {
        return (int) DB::table('inventory_stocks')
            ->where('id', $this->stockId)
            ->value('revision');
    }

    private function receiptCount(string $key): int
    {
        return DB::table('inventory_receipts')
            ->where('receipt_key', $key)
            ->count();
    }

    private function movementCount(string $key): int
    {
        return DB::table('inventory_movements')
            ->where('reference', 'RCV-' . $key)
            ->count();
    }

    /**
     * Тестовые данные удаляем вручную.
     *
     * DatabaseTransactions здесь не используем:
     * другие PHP-процессы должны видеть записи.
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
