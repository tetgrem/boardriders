<?php

namespace Tests\Feature;

use App\Services\Pricing\SupplierPriceService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SupplierPriceServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SupplierPriceService $prices;
    private int $supplierId;
    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Only boardriders_test is allowed.'
            );
        }

        $this->prices = app(SupplierPriceService::class);

        $now = now();
        $suffix = (string) Str::uuid();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Українська гривня',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->supplierId = $this->makeSupplier();

        $productId = DB::table('products')->insertGetId([
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'COST-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function makeSupplier(): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'code' => 'COST-SUP-' . Str::uuid(),
            'name' => 'Тестовий постачальник',
            'country_code' => 'UA',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setCost(
        int $cost,
        string $changeKey,
        int $revision,
        ?int $supplierId = null,
        int $minOrderQuantity = 1,
        string $taxMode = 'net'
    ): int {
        return $this->prices->setPrice(
            $supplierId ?? $this->supplierId,
            $this->variantId,
            'UAH',
            $cost,
            $taxMode,
            $minOrderQuantity,
            'SUPPLIER-OFFER-001',
            $changeKey,
            $revision
        );
    }

    public function test_creates_supplier_price_with_history(): void
    {
        $key = (string) Str::uuid();

        $id = $this->setCost(
            145000,
            $key,
            0,
            null,
            3,
            'gross'
        );

        $price = $this->prices->getPrice(
            $this->supplierId,
            $this->variantId,
            'uah'
        );

        $this->assertNotNull($price);
        $this->assertEquals(145000, $price->cost_minor);
        $this->assertEquals(3, $price->min_order_quantity);
        $this->assertSame('gross', $price->tax_mode);
        $this->assertEquals(1, $price->revision);

        $this->assertDatabaseHas('supplier_price_changes', [
            'id' => $id,
            'change_key' => $key,
            'previous_cost_minor' => null,
            'new_cost_minor' => 145000,
            'previous_revision' => 0,
            'new_revision' => 1,
        ]);
    }

    public function test_updates_supplier_price_with_revision_and_history(): void
    {
        $this->setCost(
            145000,
            (string) Str::uuid(),
            0
        );

        $id = $this->setCost(
            120000,
            (string) Str::uuid(),
            1,
            null,
            2
        );

        $price = $this->prices->getPrice(
            $this->supplierId,
            $this->variantId,
            'UAH'
        );

        $this->assertEquals(120000, $price->cost_minor);
        $this->assertEquals(2, $price->revision);

        $this->assertDatabaseHas('supplier_price_changes', [
            'id' => $id,
            'previous_cost_minor' => 145000,
            'new_cost_minor' => 120000,
            'previous_min_order_quantity' => 1,
            'new_min_order_quantity' => 2,
            'previous_revision' => 1,
            'new_revision' => 2,
        ]);
    }

    public function test_duplicate_uuid_is_idempotent(): void
    {
        $key = (string) Str::uuid();

        $first = $this->setCost(80000, $key, 0);
        $second = $this->setCost(80000, $key, 0);

        $this->assertSame($first, $second);

        $this->assertSame(
            1,
            DB::table('supplier_price_changes')
                ->where('change_key', $key)
                ->count()
        );

        $this->assertEquals(
            1,
            $this->prices->getPrice(
                $this->supplierId,
                $this->variantId,
                'UAH'
            )->revision
        );
    }

    public function test_same_uuid_cannot_change_request_parameters(): void
    {
        $key = (string) Str::uuid();

        $this->setCost(80000, $key, 0);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->setCost(90000, $key, 0);
    }

    public function test_stale_revision_cannot_overwrite_supplier_price(): void
    {
        $this->setCost(
            80000,
            (string) Str::uuid(),
            0
        );

        try {
            $this->setCost(
                90000,
                (string) Str::uuid(),
                0
            );

            $this->fail(
                'Stale revision should be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Оновіть',
                $exception->getMessage()
            );
        }

        $this->assertEquals(
            80000,
            $this->prices->getPrice(
                $this->supplierId,
                $this->variantId,
                'UAH'
            )->cost_minor
        );
    }

    public function test_two_suppliers_can_have_different_costs(): void
    {
        $otherSupplierId = $this->makeSupplier();

        $this->setCost(
            80000,
            (string) Str::uuid(),
            0
        );

        $this->setCost(
            70000,
            (string) Str::uuid(),
            0,
            $otherSupplierId
        );

        $this->assertEquals(
            80000,
            $this->prices->getPrice(
                $this->supplierId,
                $this->variantId,
                'UAH'
            )->cost_minor
        );

        $this->assertEquals(
            70000,
            $this->prices->getPrice(
                $otherSupplierId,
                $this->variantId,
                'UAH'
            )->cost_minor
        );
    }
}
