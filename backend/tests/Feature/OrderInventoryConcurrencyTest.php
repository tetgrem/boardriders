<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OrderInventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real PostgreSQL concurrency checks: DO NOT use DatabaseTransactions.
 * Workers must see committed setup rows.
 */
class OrderInventoryConcurrencyTest extends TestCase
{
    private InventoryService $inventory;
    private OrderInventoryService $orders;
    private int $warehouseId = 0;
    private int $productId = 0;
    private int $variantId = 0;
    private int $stockId = 0;
    private int $orderId = 0;
    private int $reservationId = 0;
    private string $orderKey = '';
    private string $reservationKey = '';
    private ?string $gateFile = null;

    /** @var Process[] */
    private array $workers = [];

    /** @var string[] */
    private array $outputs = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException('Only boardriders_test is allowed.');
        }

        $this->inventory = app(InventoryService::class);
        $this->orders = app(OrderInventoryService::class);

        $now = now();
        $suffix = (string) Str::uuid();

        $this->warehouseId = DB::table('warehouses')->insertGetId([
            'code' => 'ORDER-RACE-' . $suffix,
            'name' => 'Order race testing warehouse',
            'kind' => 'owned',
            'country_code' => 'UA',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->productId = DB::table('products')->insertGetId([
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $this->productId,
            'sku' => 'ORDER-RACE-' . $suffix,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'ORDER-RACE-RECEIPT-' . $suffix,
            (string) Str::uuid()
        );

        $this->stockId = (int) $stock->id;
        $this->orderKey = (string) Str::uuid();
        $this->reservationKey = (string) Str::uuid();
        $this->orderId = $this->orders->createPendingOrder($this->orderKey);

        $reservation = $this->orders->reserveLine(
            $this->orderKey,
            $this->warehouseId,
            $this->variantId,
            4,
            'ORDER-RACE-LINE-' . $suffix,
            $this->reservationKey,
            now()->addHour()
        );

        $this->reservationId = (int) $reservation->id;
    }

    /**
     * Payment vs expiration for an already-expired reservation:
     * whether the expiration wins or loses, late payment enters review.
     * Inventory must never be released twice.
     */
    public function test_concurrent_late_payment_and_expiration_preserve_consistency(): void
    {
        $this->expireReservationForTest();

        $results = $this->runRace(
            ['pay', $this->orderKey],
            ['expire', $this->reservationKey]
        );

        $this->assertSame([0, 0], $results, implode("\n", $this->outputs));
        $this->assertDatabaseHas('orders', [
            'id' => $this->orderId,
            'payment_status' => 'review',
        ]);

        $released = (int) DB::table('inventory_reservations')
            ->where('id', $this->reservationId)
            ->value('released_qty');

        $this->assertContains($released, [0, 4]);
        $this->assertStock(10, 4 - $released);
        $this->assertSame(
            $released === 4 ? 1 : 0,
            $this->releaseOperationCount()
        );

        // Review orders are protected from any later auto-expiration.
        $this->assertFalse(
            $this->inventory->expireOrderReservation($this->reservationKey)
        );
        $this->assertSame(
            'review',
            $this->orders->registerCapturedPayment($this->orderKey)
        );
    }

    /** Two expiration processes cannot release the same reserve twice. */
    public function test_simultaneous_duplicate_expirations_release_only_once(): void
    {
        $this->expireReservationForTest();

        $results = $this->runRace(
            ['expire', $this->reservationKey],
            ['expire', $this->reservationKey]
        );

        $this->assertSame([0, 0], $results, implode("\n", $this->outputs));
        $this->assertSame(1, count(array_filter(
            $this->outputs,
            fn (string $output): bool => str_contains($output, 'EXPIRE:1')
        )));
        $this->assertSame(1, count(array_filter(
            $this->outputs,
            fn (string $output): bool => str_contains($output, 'EXPIRE:0')
        )));

        $this->assertStock(10, 0);
        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $this->reservationId,
            'released_qty' => 4,
        ]);
        $this->assertSame(1, $this->releaseOperationCount());
    }

    /** Payment is confirmed while the reservation is valid; expiry must not release it. */
    public function test_concurrent_payment_and_early_expiration_keep_paid_stock(): void
    {
        $results = $this->runRace(
            ['pay', $this->orderKey],
            ['expire', $this->reservationKey]
        );

        $this->assertSame([0, 0], $results, implode("\n", $this->outputs));
        $this->assertDatabaseHas('orders', [
            'id' => $this->orderId,
            'payment_status' => 'paid',
        ]);
        $this->assertStock(10, 4);
        $this->assertSame(0, $this->releaseOperationCount());

        // Even if a scheduler finds the reservation expired later,
        // a paid order cannot be auto-released.
        $this->expireReservationForTest();
        $this->assertFalse(
            $this->inventory->expireOrderReservation($this->reservationKey)
        );
        $this->assertStock(10, 4);
    }

    private function expireReservationForTest(): void
    {
        DB::table('inventory_reservations')
            ->where('id', $this->reservationId)
            ->update(['expires_at' => now()->subMinute()]);
    }

    private function releaseOperationCount(): int
    {
        return DB::table('inventory_reservation_operations')
            ->where('inventory_reservation_id', $this->reservationId)
            ->where('type', 'release')
            ->count();
    }

    private function assertStock(int $onHand, int $reserved): void
    {
        $this->assertDatabaseHas('inventory_stocks', [
            'id' => $this->stockId,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    /** @param array{string,string} $first @param array{string,string} $second */
    private function runRace(array $first, array $second): array
    {
        $this->gateFile = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'boardriders-order-race-'
            . Str::uuid() . '.go';

        $this->workers = [];
        $this->outputs = [];

        foreach ([$first, $second] as [$operation, $key]) {
            $process = new Process(
                [
                    PHP_BINARY,
                    base_path('tests/Support/order-inventory-worker.php'),
                    $operation,
                    $key,
                    $this->gateFile,
                ],
                base_path(),
                ['APP_ENV' => 'testing']
            );

            $process->setTimeout(30);
            $this->workers[] = $process;
            $process->start();
        }

        $deadline = microtime(true) + 15;

        while (
            !str_contains($this->workers[0]->getOutput(), 'READY')
            || !str_contains($this->workers[1]->getOutput(), 'READY')
        ) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    "Order workers failed to start:\n"
                    . $this->workers[0]->getErrorOutput() . "\n"
                    . $this->workers[1]->getErrorOutput()
                );
            }

            usleep(20000);
        }

        if (file_put_contents($this->gateFile, 'GO') === false) {
            throw new RuntimeException('Cannot create order race gate.');
        }

        $exitCodes = [];

        foreach ($this->workers as $worker) {
            $worker->wait();
            $exitCodes[] = $worker->getExitCode();
            $this->outputs[] = $worker->getOutput() . $worker->getErrorOutput();
        }

        return $exitCodes;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }

            if ($this->gateFile !== null && is_file($this->gateFile)) {
                unlink($this->gateFile);
            }

            if ($this->reservationId > 0) {
                DB::table('inventory_reservation_operations')
                    ->where('inventory_reservation_id', $this->reservationId)
                    ->delete();

                DB::table('inventory_reservations')
                    ->where('id', $this->reservationId)
                    ->delete();
            }

            if ($this->orderId > 0) {
                DB::table('orders')->where('id', $this->orderId)->delete();
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
                DB::table('product_variants')->where('id', $this->variantId)->delete();
            }

            if ($this->productId > 0) {
                DB::table('products')->where('id', $this->productId)->delete();
            }

            if ($this->warehouseId > 0) {
                DB::table('warehouses')->where('id', $this->warehouseId)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }
}
