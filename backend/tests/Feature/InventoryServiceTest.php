<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Services\Inventory\InventoryService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Illuminate\Support\Str;

class InventoryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryService $inventory;

    private int $warehouseId;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        // Захист від запуску тестів в основній БД.
        if (
            config('database.default') !== 'pgsql' ||
            DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Тести дозволено виконувати лише в boardriders_test.'
            );
        }

        $this->inventory = app(InventoryService::class);

        $now = now();

        // Тестовий склад.
        $this->warehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'TEST-WH',
                'name' => 'Тестовий склад',
                'kind' => 'owned',
                'country_code' => 'UA',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // Тестовий товар.
        $productId = DB::table('products')
            ->insertGetId([
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // Тестовий SKU.
        $this->variantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'TEST-SKU-001',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function test_receiving_products(): void
    {
        $receiptKey = (string) \Illuminate\Support\Str::uuid();
        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-001',
            $receiptKey
        );

        $this->assertEquals(10, $stock->on_hand_qty);
        $this->assertEquals(0, $stock->reserved_qty);
        $this->assertEquals(10, $stock->available_qty);


        $this->assertDatabaseHas('inventory_receipts', [
            'receipt_key' => $receiptKey,
            'inventory_stock_id' => $stock->id,
            'quantity' => 10,
            'reference' => 'RECEIPT-001',
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_stock_id' => $stock->id,
            'type' => 'receipt',
            'quantity_delta' => 10,
            'reference' => 'RCV-' . $receiptKey,
        ]);

    }








    public function test_reserving_products(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-002',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-002',
            (string) Str::uuid()
        );

        $stock = InventoryStock::findOrFail(
            $reservation->inventory_stock_id
        );

        $this->assertEquals(10, $stock->on_hand_qty);
        $this->assertEquals(3, $stock->reserved_qty);
        $this->assertEquals(7, $stock->available_qty);
        $this->assertEquals(3, $reservation->active_qty);
    }

    public function test_shipping_reserved_products(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-003',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-003',
            (string) Str::uuid()
        );

        $operationKey = (string) Str::uuid();

        $reservation = $this->inventory->shipOrderReservation(
            $reservation->reservation_key,
            2,
            $operationKey
        );

        $stock = InventoryStock::findOrFail(
            $reservation->inventory_stock_id
        );

        $this->assertEquals(8, $stock->on_hand_qty);
        $this->assertEquals(1, $stock->reserved_qty);
        $this->assertEquals(7, $stock->available_qty);
        $this->assertEquals(1, $reservation->active_qty);

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_stock_id' => $stock->id,
            'type' => 'sale',
            'quantity_delta' => -2,
            'reference' => 'SHIP-' . $operationKey,
        ]);
    }

    public function test_releasing_reservation(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-004',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-004',
            (string) Str::uuid()
        );

        $reservation = $this->inventory->releaseOrderReservation(
            $reservation->reservation_key,
            3,
            (string) Str::uuid()
        );

        $stock = InventoryStock::findOrFail(
            $reservation->inventory_stock_id
        );

        $this->assertEquals(10, $stock->on_hand_qty);
        $this->assertEquals(0, $stock->reserved_qty);
        $this->assertEquals(10, $stock->available_qty);
        $this->assertEquals(0, $reservation->active_qty);
    }

    public function test_cannot_reserve_more_than_available(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            5,
            'RECEIPT-005',
            (string) \Illuminate\Support\Str::uuid()
        );

        try {
            $this->inventory->reserveForOrder(
                $this->warehouseId,
                $this->variantId,
                10,
                'ORDER-005',
                (string) Str::uuid()
            );

            $this->fail(
                'Система дозволила зарезервувати зайвий товар.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Недостатньо товару',
                $exception->getMessage()
            );
        }

        $stock = InventoryStock::query()
            ->where('warehouse_id', $this->warehouseId)
            ->where('variant_id', $this->variantId)
            ->firstOrFail();

        $this->assertEquals(5, $stock->on_hand_qty);
        $this->assertEquals(0, $stock->reserved_qty);

        $this->assertDatabaseCount(
            'inventory_reservations',
            0
        );
    }



    public function test_order_reservation_is_idempotent(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-006',
            (string) \Illuminate\Support\Str::uuid()
        );

        $key = (string) Str::uuid();

        // Перше резервування.
        $first = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-10001',
            $key
        );

        // Повторюємо абсолютно той самий запит.
        $second = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-10001',
            $key
        );

        // Обидва запити повернули один резерв.
        $this->assertEquals($first->id, $second->id);

        $this->assertEquals(3, $first->active_qty);

        $this->assertDatabaseCount(
            'inventory_reservations',
            1
        );

        // Загальний резерв має бути 3, а не 6.
        $this->assertDatabaseHas('inventory_stocks', [
            'id' => $first->inventory_stock_id,
            'on_hand_qty' => 10,
            'reserved_qty' => 3,
        ]);
    }

    public function test_reservation_key_cannot_be_reused(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-007',
            (string) \Illuminate\Support\Str::uuid()
        );

        $key = (string) Str::uuid();

        $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            2,
            'ORDER-10002',
            $key
        );

        // Спроба змінити кількість з тим самим UUID.
        $this->expectException(
            \InvalidArgumentException::class
        );

        $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            5,
            'ORDER-10002',
            $key
        );
    }


    public function test_partial_shipping_and_release(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-008',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            5,
            'ORDER-10003',
            (string) Str::uuid()
        );

        $shipKey = (string) Str::uuid();
        $releaseKey = (string) Str::uuid();

        // Відвантажуємо 2 одиниці.
        $this->inventory->shipOrderReservation(
            $reservation->reservation_key,
            2,
            $shipKey
        );

        // Скасовуємо резерв 1 одиниці.
        $this->inventory->releaseOrderReservation(
            $reservation->reservation_key,
            1,
            $releaseKey
        );

        $reservation->refresh();

        $this->assertEquals(5, $reservation->quantity);
        $this->assertEquals(2, $reservation->shipped_qty);
        $this->assertEquals(1, $reservation->released_qty);
        $this->assertEquals(2, $reservation->active_qty);

        $this->assertDatabaseHas('inventory_stocks', [
            'id' => $reservation->inventory_stock_id,
            'on_hand_qty' => 8,
            'reserved_qty' => 2,
        ]);

        // Повторні запити не повинні нічого списати.
        $this->inventory->shipOrderReservation(
            $reservation->reservation_key,
            2,
            $shipKey
        );

        $this->inventory->releaseOrderReservation(
            $reservation->reservation_key,
            1,
            $releaseKey
        );

        $this->assertDatabaseCount(
            'inventory_reservation_operations',
            2
        );

        $this->assertDatabaseHas('inventory_stocks', [
            'id' => $reservation->inventory_stock_id,
            'on_hand_qty' => 8,
            'reserved_qty' => 2,
        ]);

        $this->assertEquals(
            1,
            DB::table('inventory_movements')
                ->where('reference', 'SHIP-' . $shipKey)
                ->count()
        );
    }

    public function test_cannot_ship_more_than_reserved(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            5,
            'RECEIPT-009',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            2,
            'ORDER-10004',
            (string) Str::uuid()
        );

        $this->expectException(\DomainException::class);

        $this->inventory->shipOrderReservation(
            $reservation->reservation_key,
            3,
            (string) Str::uuid()
        );
    }

    public function test_operation_key_cannot_be_reused(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            5,
            'RECEIPT-010',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-10005',
            (string) Str::uuid()
        );

        $operationKey = (string) Str::uuid();

        $this->inventory->shipOrderReservation(
            $reservation->reservation_key,
            1,
            $operationKey
        );

        // Той самий ключ не можна використати
        // для іншого типу операції.
        $this->expectException(
            \InvalidArgumentException::class
        );

        $this->inventory->releaseOrderReservation(
            $reservation->reservation_key,
            1,
            $operationKey
        );
    }


    public function test_expired_reservation_is_released_once(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-011',
            (string) \Illuminate\Support\Str::uuid()
        );

        $key = (string) Str::uuid();

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-10006',
            $key,
            now()->addMinutes(15)
        );

        // Резерв ще активний, звільняти зарано.
        $this->assertFalse(
            $this->inventory->expireOrderReservation($key)
        );

        // Імітуємо завершення строку дії.
        DB::table('inventory_reservations')
            ->where('id', $reservation->id)
            ->update([
                'expires_at' => now()->subMinute(),
            ]);

        // Перше завершення успішне.
        $this->assertTrue(
            $this->inventory->expireOrderReservation($key)
        );

        // Повторне завершення нічого не змінює.
        $this->assertFalse(
            $this->inventory->expireOrderReservation($key)
        );

        $reservation->refresh();

        $stock = InventoryStock::findOrFail(
            $reservation->inventory_stock_id
        );

        $this->assertEquals(0, $reservation->active_qty);
        $this->assertEquals(3, $reservation->released_qty);
        $this->assertEquals(10, $stock->on_hand_qty);
        $this->assertEquals(0, $stock->reserved_qty);

        $this->assertEquals(
            1,
            DB::table('inventory_reservation_operations')
                ->where('inventory_reservation_id', $reservation->id)
                ->where('type', 'release')
                ->count()
        );
    }

    public function test_expiration_only_releases_remaining_quantity(): void
    {
        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-012',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            5,
            'ORDER-10007',
            (string) Str::uuid(),
            now()->addMinutes(15)
        );

        // Дві одиниці вже відвантажено.
        $this->inventory->shipOrderReservation(
            $reservation->reservation_key,
            2,
            (string) Str::uuid()
        );

        // Завершуємо строк резервування.
        DB::table('inventory_reservations')
            ->where('id', $reservation->id)
            ->update([
                'expires_at' => now()->subMinute(),
            ]);

        $this->assertTrue(
            $this->inventory->expireOrderReservation(
                $reservation->reservation_key
            )
        );

        $reservation->refresh();

        $stock = InventoryStock::findOrFail(
            $reservation->inventory_stock_id
        );

        // Відвантажені одиниці залишаються відвантаженими.
        $this->assertEquals(2, $reservation->shipped_qty);

        // Решта трьох звільнена.
        $this->assertEquals(3, $reservation->released_qty);

        $this->assertEquals(0, $reservation->active_qty);

        // Фізичний залишок зменшився лише на 2.
        $this->assertEquals(8, $stock->on_hand_qty);

        // Активного резерву більше немає.
        $this->assertEquals(0, $stock->reserved_qty);
    }


    public function test_failed_shipment_does_not_change_inventory(): void
    {
        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            6,
            'RECEIPT-013',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            2,
            'ORDER-10008',
            (string) Str::uuid()
        );

        $operationKey = (string) Str::uuid();

        // Намагаємося відвантажити 3, хоча резерв лише 2.
        try {
            $this->inventory->shipOrderReservation(
                $reservation->reservation_key,
                3,
                $operationKey
            );

            $this->fail('Відвантаження мало бути відхилене.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Недостатньо товару',
                $exception->getMessage()
            );
        }

        $stock->refresh();
        $reservation->refresh();

        // Залишки не повинні змінитися.
        $this->assertEquals(6, $stock->on_hand_qty);
        $this->assertEquals(2, $stock->reserved_qty);
        $this->assertEquals(0, $reservation->shipped_qty);
        $this->assertEquals(2, $reservation->active_qty);

        // Невдала операція не повинна потрапити в журнал.
        $this->assertEquals(
            0,
            DB::table('inventory_reservation_operations')
                ->where('inventory_reservation_id', $reservation->id)
                ->count()
        );

        $this->assertEquals(
            0,
            DB::table('inventory_movements')
                ->where('reference', 'SHIP-' . $operationKey)
                ->count()
        );
    }

    public function test_failed_release_does_not_change_inventory(): void
    {
        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            5,
            'RECEIPT-014',
            (string) \Illuminate\Support\Str::uuid()
        );

        $reservation = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-10009',
            (string) Str::uuid()
        );

        // Відвантажуємо 2. Активний резерв тепер 1.
        $this->inventory->shipOrderReservation(
            $reservation->reservation_key,
            2,
            (string) Str::uuid()
        );

        // Намагаємося звільнити 2 замість 1.
        try {
            $this->inventory->releaseOrderReservation(
                $reservation->reservation_key,
                2,
                (string) Str::uuid()
            );

            $this->fail('Скасування мало бути відхилене.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Недостатньо товару',
                $exception->getMessage()
            );
        }

        $stock->refresh();
        $reservation->refresh();

        $this->assertEquals(3, $stock->on_hand_qty);
        $this->assertEquals(1, $stock->reserved_qty);

        $this->assertEquals(2, $reservation->shipped_qty);
        $this->assertEquals(0, $reservation->released_qty);
        $this->assertEquals(1, $reservation->active_qty);

        // Лише одна успішна операція відвантаження.
        $this->assertEquals(
            1,
            DB::table('inventory_reservation_operations')
                ->where('inventory_reservation_id', $reservation->id)
                ->count()
        );
    }

    public function test_operation_key_cannot_be_used_for_another_reservation(): void
    {
        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'RECEIPT-015',
            (string) \Illuminate\Support\Str::uuid()
        );

        $first = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'ORDER-10010',
            (string) Str::uuid()
        );

        $second = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            2,
            'ORDER-10011',
            (string) Str::uuid()
        );

        $operationKey = (string) Str::uuid();

        // Відвантажуємо одну одиницю першого замовлення.
        $this->inventory->shipOrderReservation(
            $first->reservation_key,
            1,
            $operationKey
        );

        // Намагаємося використати той самий UUID
        // для іншого резервування.
        try {
            $this->inventory->shipOrderReservation(
                $second->reservation_key,
                1,
                $operationKey
            );

            $this->fail('Повторне використання UUID мало бути відхилене.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'Ключ операції вже використано',
                $exception->getMessage()
            );
        }

        $stock->refresh();
        $first->refresh();
        $second->refresh();

        $this->assertEquals(9, $stock->on_hand_qty);
        $this->assertEquals(4, $stock->reserved_qty);

        $this->assertEquals(2, $first->active_qty);
        $this->assertEquals(2, $second->active_qty);

        $this->assertEquals(
            1,
            DB::table('inventory_reservation_operations')
                ->where('operation_key', $operationKey)
                ->count()
        );
    }




}
