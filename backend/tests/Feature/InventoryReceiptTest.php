<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InventoryReceiptTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryService $inventory;

    private int $warehouseId;
    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        // Запрещаем тестирование в основной базе.
        if (
            config('database.default') !== 'pgsql' ||
            DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Tests allowed only in boardriders_test.'
            );
        }

        $this->inventory = app(InventoryService::class);

        $now = now();
        $suffix = (string) Str::uuid();

        $this->warehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'RCV-WH-' . $suffix,
                'name' => 'Тестовий склад приймання',
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
                'sku' => 'RCV-SKU-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * 1. Первая приёмка создаёт остаток и движение.
     */
    public function test_receive_once_creates_stock_and_receipt(): void
    {
        $key = (string) Str::uuid();

        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'INVOICE-001',
            $key
        );

        $this->assertEquals(10, $stock->on_hand_qty);
        $this->assertEquals(0, $stock->reserved_qty);

        $this->assertDatabaseHas('inventory_receipts', [
            'receipt_key' => $key,
            'inventory_stock_id' => $stock->id,
            'quantity' => 10,
            'reference' => 'INVOICE-001',
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_stock_id' => $stock->id,
            'type' => 'receipt',
            'quantity_delta' => 10,
            'reference' => 'RCV-' . $key,
        ]);

        $this->assertSame(1, $this->receiptCount($key));
        $this->assertSame(1, $this->movementCount($key));
    }

    /**
     * 2. Повторная приёмка с тем же UUID
     * не увеличивает остаток второй раз.
     */
    public function test_duplicate_receipt_is_idempotent(): void
    {
        $key = (string) Str::uuid();

        $first = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'INVOICE-002',
            $key
        );

        $revision = (int) $first->fresh()->revision;

        $second = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'INVOICE-002',
            $key
        );

        $this->assertSame($first->id, $second->id);

        // Остаток по-прежнему 10, а не 20.
        $this->assertEquals(
            10,
            $second->fresh()->on_hand_qty
        );

        // Повтор не должен менять версию склада.
        $this->assertEquals(
            $revision,
            $second->fresh()->revision
        );

        $this->assertSame(1, $this->receiptCount($key));
        $this->assertSame(1, $this->movementCount($key));
    }

    /**
     * 3. UUID нельзя использовать повторно
     * с другим количеством товара.
     */
    public function test_receipt_key_cannot_be_reused_with_different_quantity(): void
    {
        $key = (string) Str::uuid();

        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'INVOICE-003',
            $key
        );

        try {
            $this->inventory->receiveOnce(
                $this->warehouseId,
                $this->variantId,
                20,
                'INVOICE-003',
                $key
            );

            $this->fail(
                'Повторный UUID с другим количеством должен быть отклонён.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'UUID приймання вже використано',
                $exception->getMessage()
            );
        }

        $this->assertStock(10, 0);

        $this->assertSame(1, $this->receiptCount($key));
        $this->assertSame(1, $this->movementCount($key));
    }

    /**
     * 4. Две разные приёмки с разными UUID
     * суммируют количество товара.
     */
    public function test_different_receipt_keys_increase_stock(): void
    {
        $firstKey = (string) Str::uuid();
        $secondKey = (string) Str::uuid();

        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            5,
            'INVOICE-004-A',
            $firstKey
        );

        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            7,
            'INVOICE-004-B',
            $secondKey
        );

        $this->assertStock(12, 0);

        $this->assertSame(1, $this->receiptCount($firstKey));
        $this->assertSame(1, $this->receiptCount($secondKey));

        $this->assertSame(1, $this->movementCount($firstKey));
        $this->assertSame(1, $this->movementCount($secondKey));
    }

    /**
     * 5. Повторный UUID нельзя использовать
     * для приёмки на другой склад.
     */
    public function test_receipt_key_cannot_be_reused_for_another_warehouse(): void
    {
        $key = (string) Str::uuid();

        $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            5,
            'INVOICE-005',
            $key
        );

        $now = now();

        $otherWarehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'RCV-WH-' . Str::uuid(),
                'name' => 'Другий тестовий склад',
                'kind' => 'owned',
                'country_code' => 'UA',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        try {
            $this->inventory->receiveOnce(
                $otherWarehouseId,
                $this->variantId,
                5,
                'INVOICE-005',
                $key
            );

            $this->fail(
                'Повторный UUID для другого склада должен быть отклонён.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'UUID приймання вже використано',
                $exception->getMessage()
            );
        }

        $this->assertStock(5, 0);

        $this->assertSame(
            0,
            DB::table('inventory_stocks')
                ->where('warehouse_id', $otherWarehouseId)
                ->where('variant_id', $this->variantId)
                ->count()
        );

        $this->assertSame(1, $this->receiptCount($key));
        $this->assertSame(1, $this->movementCount($key));
    }

    /*
     * Вспомогательные проверки.
     */

    private function assertStock(
        int $onHand,
        int $reserved
    ): void {
        $this->assertDatabaseHas('inventory_stocks', [
            'warehouse_id' => $this->warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
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
}
