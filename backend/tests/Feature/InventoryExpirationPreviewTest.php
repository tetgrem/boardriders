<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class InventoryExpirationPreviewTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryService $inventory;

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

        $now = now();
        $suffix = (string) Str::uuid();

        $this->warehouseId = DB::table('warehouses')
            ->insertGetId([
                'code' => 'EXP-WH-' . $suffix,
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
                'sku' => 'EXP-SKU-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function test_preview_finds_only_expired_reservations(): void
    {
        $stock = $this->inventory->receiveOnce(
            $this->warehouseId,
            $this->variantId,
            10,
            'EXP-RECEIPT-001',
            (string) \Illuminate\Support\Str::uuid()
        );

        $expired = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            3,
            'EXP-001',
            (string) Str::uuid(),
            now()->addMinutes(15)
        );

        $active = $this->inventory->reserveForOrder(
            $this->warehouseId,
            $this->variantId,
            2,
            'EXP-002',
            (string) Str::uuid(),
            now()->addMinutes(30)
        );

        // Робимо перший резерв простроченим.
        DB::table('inventory_reservations')
            ->where('id', $expired->id)
            ->update([
                'expires_at' => now()->subMinute(),
            ]);

        $exitCode = Artisan::call(
            'inventory:expire',
            ['--limit' => 10]
        );

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // Прострочений резерв знайдено.
        $this->assertStringContainsString(
            'EXP-001',
            $output
        );

        // Активний резерв не відображається.
        $this->assertStringNotContainsString(
            'EXP-002',
            $output
        );

        // Команда не змінює кількості.
        $this->assertEquals(
            5,
            $stock->fresh()->reserved_qty
        );

        $this->assertEquals(
            0,
            $expired->fresh()->released_qty
        );

        $this->assertEquals(
            0,
            $active->fresh()->released_qty
        );
    }

    public function test_preview_handles_no_expired_reservations(): void
    {
        $exitCode = Artisan::call('inventory:expire');

        $this->assertSame(0, $exitCode);

        $this->assertStringContainsString(
            'Активних прострочених резервувань не знайдено.',
            Artisan::output()
        );
    }
}
