<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OrderInventoryService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrderInventorySafetyTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryService $inventory;
    private OrderInventoryService $orders;
    private int $warehouseId;
    private int $variantId;

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
        $suffix = (string) Str::uuid();
        $now = now();

        $this->warehouseId = DB::table('warehouses')->insertGetId([
            'code' => 'ORDER-GUARD-' . $suffix,
            'name' => 'Тестовий склад замовлень',
            'kind' => 'owned',
            'country_code' => 'UA',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = DB::table('products')->insertGetId([
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => 'ORDER-GUARD-' . $suffix,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'ORDER-GUARD-RECEIPT-' . $suffix,
            (string) Str::uuid()
        );
    }

    public function test_paid_order_reservation_is_not_expired(): void
    {
        [$orderKey, $reservationKey] = $this->createReservedOrder();
        $this->assertSame('paid', $this->orders->registerCapturedPayment($orderKey));

        $this->makeExpired($reservationKey);

        $this->assertFalse($this->inventory->expireOrderReservation($reservationKey));
        $this->assertStock(10, 4);
        $this->assertDatabaseHas('inventory_reservations', [
            'reservation_key' => $reservationKey,
            'shipped_qty' => 0,
            'released_qty' => 0,
        ]);
    }

    public function test_pending_order_reservation_can_expire(): void
    {
        [$orderKey, $reservationKey] = $this->createReservedOrder();
        $this->makeExpired($reservationKey);

        $this->assertTrue($this->inventory->expireOrderReservation($reservationKey));
        $this->assertFalse($this->inventory->expireOrderReservation($reservationKey));
        $this->assertStock(10, 0);
        $this->assertDatabaseHas('orders', [
            'order_key' => $orderKey,
            'payment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('inventory_reservations', [
            'reservation_key' => $reservationKey,
            'released_qty' => 4,
        ]);
    }

    public function test_late_captured_payment_is_marked_for_review(): void
    {
        [$orderKey, $reservationKey] = $this->createReservedOrder();
        $this->makeExpired($reservationKey);
        $this->assertTrue($this->inventory->expireOrderReservation($reservationKey));

        $this->assertSame('review', $this->orders->registerCapturedPayment($orderKey));
        $this->assertSame('review', $this->orders->registerCapturedPayment($orderKey));
        $this->assertStock(10, 0);
        $this->assertDatabaseHas('orders', [
            'order_key' => $orderKey,
            'payment_status' => 'review',
        ]);
    }

    public function test_order_line_reservation_retry_is_idempotent(): void
    {
        $orderKey = (string) Str::uuid();
        $reservationKey = (string) Str::uuid();
        $expires = now()->addHour();
        $this->orders->createPendingOrder($orderKey);

        $first = $this->orders->reserveLine(
            $orderKey, $this->warehouseId, $this->variantId,
            4, 'ORDER-GUARD-LINE', $reservationKey, $expires
        );
        $second = $this->orders->reserveLine(
            $orderKey, $this->warehouseId, $this->variantId,
            4, 'ORDER-GUARD-LINE', $reservationKey, $expires
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('inventory_reservations')
            ->where('reservation_key', $reservationKey)->count());
        $this->assertStock(10, 4);
    }

    public function test_paid_order_cannot_reserve_another_line(): void
    {
        [$orderKey] = $this->createReservedOrder();
        $this->assertSame('paid', $this->orders->registerCapturedPayment($orderKey));
        $newKey = (string) Str::uuid();

        try {
            $this->orders->reserveLine(
                $orderKey, $this->warehouseId, $this->variantId,
                2, 'ORDER-GUARD-LATE-LINE', $newKey, now()->addHour()
            );
            $this->fail('Paid order should not accept a new reservation.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('не можна', $e->getMessage());
        }

        $this->assertSame(0, DB::table('inventory_reservations')
            ->where('reservation_key', $newKey)->count());
        $this->assertStock(10, 4);
    }

    public function test_paid_order_cannot_be_released_manually(): void
    {
        [$orderKey, $reservationKey] = $this->createReservedOrder();
        $this->assertSame('paid', $this->orders->registerCapturedPayment($orderKey));

        $operationKey = (string) Str::uuid();

        try {
            $this->inventory->releaseOrderReservation($reservationKey, 4, $operationKey);
            $this->fail('Paid order reservation must not be released.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('не можна', $exception->getMessage());
        }

        $this->assertStock(10, 4);
        $this->assertSame(0, DB::table('inventory_reservation_operations')
            ->where('operation_key', $operationKey)->count());
    }

    public function test_review_order_cannot_be_released_manually(): void
    {
        [$orderKey, $reservationKey] = $this->createReservedOrder();
        $this->makeExpired($reservationKey);
        $this->assertSame('review', $this->orders->registerCapturedPayment($orderKey));

        $operationKey = (string) Str::uuid();

        try {
            $this->inventory->releaseOrderReservation($reservationKey, 4, $operationKey);
            $this->fail('Review order reservation must not be released.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('не можна', $exception->getMessage());
        }

        $this->assertStock(10, 4);
        $this->assertSame(0, DB::table('inventory_reservation_operations')
            ->where('operation_key', $operationKey)->count());
    }

    public function test_pending_order_can_be_released_manually(): void
    {
        [$orderKey, $reservationKey] = $this->createReservedOrder();
        $operationKey = (string) Str::uuid();

        $result = $this->inventory->releaseOrderReservation(
            $reservationKey, 4, $operationKey
        );

        $this->assertEquals(4, $result->released_qty);
        $this->assertStock(10, 0);
        $this->assertDatabaseHas('inventory_reservation_operations', [
            'operation_key' => $operationKey,
            'type' => 'release',
            'quantity' => 4,
        ]);
        $this->assertSame('review', $this->orders->registerCapturedPayment($orderKey));
    }

    public function test_pending_order_cannot_be_shipped_before_payment(): void
    {
        [, $reservationKey] = $this->createReservedOrder();
        $operationKey = (string) Str::uuid();

        try {
            $this->inventory->shipOrderReservation($reservationKey, 4, $operationKey);
            $this->fail('Pending order must not be shipped.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('не можна', $exception->getMessage());
        }

        $this->assertStock(10, 4);
        $this->assertSame(0, DB::table('inventory_reservation_operations')
            ->where('operation_key', $operationKey)->count());
    }

    public function test_paid_order_can_be_shipped(): void
    {
        [$orderKey, $reservationKey] = $this->createReservedOrder();
        $this->assertSame('paid', $this->orders->registerCapturedPayment($orderKey));

        $operationKey = (string) Str::uuid();
        $result = $this->inventory->shipOrderReservation(
            $reservationKey, 4, $operationKey
        );

        $this->assertEquals(4, $result->shipped_qty);
        $this->assertStock(6, 0);
        $this->assertDatabaseHas('inventory_movements', [
            'type' => 'sale',
            'quantity_delta' => -4,
            'reference' => 'SHIP-' . $operationKey,
        ]);
    }

    private function createReservedOrder(): array
    {
        $orderKey = (string) Str::uuid();
        $reservationKey = (string) Str::uuid();

        $this->orders->createPendingOrder($orderKey);
        $this->orders->reserveLine(
            $orderKey, $this->warehouseId, $this->variantId,
            4, 'ORDER-GUARD-LINE', $reservationKey, now()->addHour()
        );

        return [$orderKey, $reservationKey];
    }

    private function makeExpired(string $reservationKey): void
    {
        DB::table('inventory_reservations')
            ->where('reservation_key', $reservationKey)
            ->update(['expires_at' => now()->subMinute()]);
    }

    private function assertStock(int $onHand, int $reserved): void
    {
        $this->assertDatabaseHas('inventory_stocks', [
            'warehouse_id' => $this->warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }
}
