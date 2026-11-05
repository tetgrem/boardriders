<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InventoryTransferTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryService $inventory;

    private int $sourceWarehouseId;
    private int $targetWarehouseId;
    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        // Защищаем основную базу от тестовых операций.
        if (
            config('database.default') !== 'pgsql' ||
            DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Тесты разрешены только в boardriders_test.'
            );
        }

        $this->inventory = app(InventoryService::class);

        $now = now();
        $suffix = (string) Str::uuid();

        // Первый склад.
        $this->sourceWarehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'TRF-SRC-' . $suffix,
                'name' => 'Тестовий склад Київ',
                'kind' => 'owned',
                'country_code' => 'UA',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // Второй склад.
        $this->targetWarehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'TRF-DST-' . $suffix,
                'name' => 'Тестовий склад Львів',
                'kind' => 'owned',
                'country_code' => 'UA',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $productId = DB::table('products')
            ->insertGetId([
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->variantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'TRF-SKU-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * 1. Успешное перемещение товара.
     */
    public function test_successful_transfer_between_warehouses(): void
    {
        $this->inventory->receiveOnce(
            $this->sourceWarehouseId,
            $this->variantId,
            10,
            'TRF-RECEIPT-001',
            (string) \Illuminate\Support\Str::uuid()
        );

        $key = (string) Str::uuid();

        $transferId = $this->inventory->transfer(
            $this->sourceWarehouseId,
            $this->targetWarehouseId,
            $this->variantId,
            4,
            'TRANSFER-001',
            $key
        );

        $this->assertGreaterThan(0, $transferId);

        $this->assertStock(
            $this->sourceWarehouseId,
            6,
            0
        );

        $this->assertStock(
            $this->targetWarehouseId,
            4,
            0
        );

        $this->assertDatabaseHas('inventory_transfers', [
            'id' => $transferId,
            'transfer_key' => $key,
            'quantity' => 4,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'type' => 'transfer_out',
            'quantity_delta' => -4,
            'reference' => 'TRF-' . $key,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'type' => 'transfer_in',
            'quantity_delta' => 4,
            'reference' => 'TRF-' . $key,
        ]);

        $this->assertSame(2, $this->movementCount($key));
    }

    /**
     * 2. Нельзя перемещать зарезервированный товар.
     */
    public function test_cannot_transfer_reserved_products(): void
    {
        $this->inventory->receiveOnce(
            $this->sourceWarehouseId,
            $this->variantId,
            10,
            'TRF-RECEIPT-002',
            (string) \Illuminate\Support\Str::uuid()
        );

        $this->inventory->reserveForOrder(
            $this->sourceWarehouseId,
            $this->variantId,
            8,
            'ORDER-TRANSFER-001',
            (string) Str::uuid()
        );

        // На складе 10, но свободно только 2.
        $failedKey = (string) Str::uuid();

        try {
            $this->inventory->transfer(
                $this->sourceWarehouseId,
                $this->targetWarehouseId,
                $this->variantId,
                3,
                'TRANSFER-002',
                $failedKey
            );

            $this->fail(
                'Система разрешила перемещение резервного товара.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Недостатньо доступного товару',
                $exception->getMessage()
            );
        }

        // Ошибка не должна изменить остатки.
        $this->assertStock(
            $this->sourceWarehouseId,
            10,
            8
        );

        $this->assertSame(0, $this->transferCount($failedKey));
        $this->assertSame(0, $this->movementCount($failedKey));

        // А вот 2 свободные единицы переместить можно.
        $this->inventory->transfer(
            $this->sourceWarehouseId,
            $this->targetWarehouseId,
            $this->variantId,
            2,
            'TRANSFER-002-OK',
            (string) Str::uuid()
        );

        $this->assertStock(
            $this->sourceWarehouseId,
            8,
            8
        );

        $this->assertStock(
            $this->targetWarehouseId,
            2,
            0
        );
    }

    /**
     * 3. Повторный UUID не создаёт второе перемещение.
     */
    public function test_transfer_is_idempotent(): void
    {
        $this->inventory->receiveOnce(
            $this->sourceWarehouseId,
            $this->variantId,
            10,
            'TRF-RECEIPT-003',
            (string) \Illuminate\Support\Str::uuid()
        );

        $key = (string) Str::uuid();

        $firstId = $this->inventory->transfer(
            $this->sourceWarehouseId,
            $this->targetWarehouseId,
            $this->variantId,
            4,
            'TRANSFER-003',
            $key
        );

        $secondId = $this->inventory->transfer(
            $this->sourceWarehouseId,
            $this->targetWarehouseId,
            $this->variantId,
            4,
            'TRANSFER-003',
            $key
        );

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, $this->transferCount($key));
        $this->assertSame(2, $this->movementCount($key));

        // Товар был перемещён только один раз.
        $this->assertStock(
            $this->sourceWarehouseId,
            6,
            0
        );

        $this->assertStock(
            $this->targetWarehouseId,
            4,
            0
        );
    }

    /**
     * 4. UUID нельзя повторно использовать
     * с другой количеством.
     */
    public function test_transfer_key_cannot_be_reused(): void
    {
        $this->inventory->receiveOnce(
            $this->sourceWarehouseId,
            $this->variantId,
            10,
            'TRF-RECEIPT-004',
            (string) \Illuminate\Support\Str::uuid()
        );

        $key = (string) Str::uuid();

        $this->inventory->transfer(
            $this->sourceWarehouseId,
            $this->targetWarehouseId,
            $this->variantId,
            3,
            'TRANSFER-004',
            $key
        );

        try {
            $this->inventory->transfer(
                $this->sourceWarehouseId,
                $this->targetWarehouseId,
                $this->variantId,
                5,
                'TRANSFER-004',
                $key
            );

            $this->fail(
                'Повторное использование UUID должно быть запрещено.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'UUID переміщення вже використано',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $this->transferCount($key));
        $this->assertSame(2, $this->movementCount($key));

        $this->assertStock(
            $this->sourceWarehouseId,
            7,
            0
        );

        $this->assertStock(
            $this->targetWarehouseId,
            3,
            0
        );
    }

    /**
     * 5. Обратное перемещение между складами.
     */
    public function test_reverse_transfer_preserves_total_stock(): void
    {
        $this->inventory->receiveOnce(
            $this->sourceWarehouseId,
            $this->variantId,
            10,
            'TRF-RECEIPT-005-A',
            (string) \Illuminate\Support\Str::uuid()
        );

        $this->inventory->receiveOnce(
            $this->targetWarehouseId,
            $this->variantId,
            2,
            'TRF-RECEIPT-005-B',
            (string) \Illuminate\Support\Str::uuid()
        );

        // Киев -> Львов: 4 единицы.
        $this->inventory->transfer(
            $this->sourceWarehouseId,
            $this->targetWarehouseId,
            $this->variantId,
            4,
            'TRANSFER-005-A',
            (string) Str::uuid()
        );

        // Львов -> Киев: 3 единицы.
        $this->inventory->transfer(
            $this->targetWarehouseId,
            $this->sourceWarehouseId,
            $this->variantId,
            3,
            'TRANSFER-005-B',
            (string) Str::uuid()
        );

        // Киев: 10 - 4 + 3 = 9.
        $this->assertStock(
            $this->sourceWarehouseId,
            9,
            0
        );

        // Львов: 2 + 4 - 3 = 3.
        $this->assertStock(
            $this->targetWarehouseId,
            3,
            0
        );

        // Общий остаток по двум складам сохранился.
        $total = DB::table('inventory_stocks')
            ->where('variant_id', $this->variantId)
            ->whereIn('warehouse_id', [
                $this->sourceWarehouseId,
                $this->targetWarehouseId,
            ])
            ->sum('on_hand_qty');

        $this->assertEquals(12, $total);
    }

    /**
     * 6. Исходный товар отсутствует.
     */
    public function test_cannot_transfer_missing_stock(): void
    {
        $key = (string) Str::uuid();

        try {
            $this->inventory->transfer(
                $this->sourceWarehouseId,
                $this->targetWarehouseId,
                $this->variantId,
                2,
                'TRANSFER-006',
                $key
            );

            $this->fail(
                'Нельзя переместить отсутствующий товар.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'товар відсутній',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $this->transferCount($key));
        $this->assertSame(0, $this->movementCount($key));
    }

    /*
     * Вспомогательные проверки.
     */

    private function assertStock(
        int $warehouseId,
        int $onHand,
        int $reserved
    ): void {
        $this->assertDatabaseHas('inventory_stocks', [
            'warehouse_id' => $warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    private function transferCount(string $key): int
    {
        return DB::table('inventory_transfers')
            ->where('transfer_key', $key)
            ->count();
    }

    private function movementCount(string $key): int
    {
        return DB::table('inventory_movements')
            ->where('reference', 'TRF-' . $key)
            ->count();
    }
}
