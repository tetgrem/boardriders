<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryIntegrityService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class InventoryIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryService $inventory;
    private InventoryIntegrityService $integrity;

    private int $warehouseId;
    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql' ||
            DB::connection()->getDatabaseName()
            !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Тести дозволені лише в boardriders_test.'
            );
        }

        $this->inventory = app(InventoryService::class);
        $this->integrity = app(InventoryIntegrityService::class);

        $now = now();
        $suffix = (string) Str::uuid();

        $this->warehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'INT-WH-' . $suffix,
                'name' => 'Тестовий склад',
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
                'sku' => 'INT-SKU-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function test_correct_reservations_have_no_mismatch(): void
    {
        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'INT-RECEIPT-001',
            (string) \Illuminate\Support\Str::uuid()
        );

        $first = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            4,
            'INT-ORDER-001',
            (string) Str::uuid()
        );

        $second = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            2,
            'INT-ORDER-002',
            (string) Str::uuid()
        );

        $this->inventory->shipOrderReservation(
            $first->reservation_key,
            1,
            (string) Str::uuid()
        );

        $this->inventory->releaseOrderReservation(
            $second->reservation_key,
            2,
            (string) Str::uuid()
        );

        // У резерві залишилося 3 одиниці.
        $this->assertEquals(
            3,
            $stock->fresh()->reserved_qty
        );

        $mismatches = $this->integrity
            ->findMismatches(50, $stock->id);

        $this->assertCount(0, $mismatches);
    }

    public function test_incorrect_reservation_is_detected(): void
    {
        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'INT-RECEIPT-002',
            (string) \Illuminate\Support\Str::uuid()
        );

        $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'INT-ORDER-003',
            (string) Str::uuid()
        );

        /*
         * Навмисно створюємо некоректний стан
         * лише в тестовій базі.
         */
        DB::table('inventory_stocks')
            ->where('id', $stock->id)
            ->update(['reserved_qty' => 2]);

        $mismatches = $this->integrity
            ->findMismatches(50, $stock->id);

        $this->assertCount(1, $mismatches);

        $problem = $mismatches->first();

        $this->assertEquals(
            2,
            $problem->reserved_qty
        );

        $this->assertEquals(
            3,
            $problem->expected_qty
        );
    }
}
