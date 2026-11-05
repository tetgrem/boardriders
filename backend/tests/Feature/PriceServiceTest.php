<?php

namespace Tests\Feature;

use App\Services\Pricing\PriceService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PriceServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PriceService $prices;
    private int $variantId;
    private string $listCode;

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

        $this->prices = app(PriceService::class);

        $now = now();
        $suffix = (string) Str::uuid();
        $this->listCode = 'TEST-PRICE-' . $suffix;

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Українська гривня',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('price_lists')->insert([
            'code' => $this->listCode,
            'name' => 'Test retail price list',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = DB::table('products')->insertGetId([
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'PRICE-' . $suffix,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function test_creates_price_in_minor_units_with_history(): void
    {
        $key = (string) Str::uuid();
        $changeId = $this->set(249990, 299990, $key, 0);

        $price = $this->prices->getPrice(
            $this->variantId,
            $this->listCode
        );

        $this->assertNotNull($price);
        $this->assertEquals(249990, $price->price_minor);
        $this->assertEquals(299990, $price->compare_at_minor);
        $this->assertEquals(1, $price->revision);
        $this->assertSame('UAH', $price->currency_code);
        $this->assertEquals(2, $price->minor_units);

        $this->assertDatabaseHas('variant_price_changes', [
            'id' => $changeId,
            'change_key' => $key,
            'previous_price_minor' => null,
            'new_price_minor' => 249990,
            'previous_revision' => 0,
            'new_revision' => 1,
        ]);
    }

    public function test_repeated_uuid_returns_original_change(): void
    {
        $key = (string) Str::uuid();

        $first = $this->set(10000, null, $key, 0);
        $second = $this->set(10000, null, $key, 0);

        $this->assertSame($first, $second);

        $this->assertEquals(
            1,
            $this->prices->getPrice(
                $this->variantId,
                $this->listCode
            )->revision
        );

        $this->assertSame(
            1,
            DB::table('variant_price_changes')
                ->where('change_key', $key)
                ->count()
        );
    }

    public function test_updates_price_and_records_history(): void
    {
        $this->set(
            15000,
            null,
            (string) Str::uuid(),
            0
        );

        $key = (string) Str::uuid();

        $changeId = $this->set(
            12000,
            15000,
            $key,
            1
        );

        $this->assertEquals(
            2,
            $this->prices->getPrice(
                $this->variantId,
                $this->listCode
            )->revision
        );

        $this->assertDatabaseHas('variant_price_changes', [
            'id' => $changeId,
            'previous_price_minor' => 15000,
            'new_price_minor' => 12000,
            'previous_revision' => 1,
            'new_revision' => 2,
        ]);
    }

    public function test_stale_revision_cannot_overwrite_price(): void
    {
        $this->set(
            15000,
            null,
            (string) Str::uuid(),
            0
        );

        try {
            $this->set(
                20000,
                null,
                (string) Str::uuid(),
                0
            );

            $this->fail(
                'Stale price revision should be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'Оновіть',
                $exception->getMessage()
            );
        }

        $price = $this->prices->getPrice(
            $this->variantId,
            $this->listCode
        );

        $this->assertEquals(15000, $price->price_minor);

        $this->assertSame(
            1,
            DB::table('variant_price_changes')
                ->where('variant_price_id', $price->id)
                ->count()
        );
    }

    public function test_cannot_reuse_uuid_with_different_amount(): void
    {
        $key = (string) Str::uuid();

        $this->set(15000, null, $key, 0);

        try {
            $this->set(13000, null, $key, 0);

            $this->fail(
                'Different request with same UUID should fail.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'UUID',
                $exception->getMessage()
            );
        }

        $this->assertEquals(
            15000,
            $this->prices->getPrice(
                $this->variantId,
                $this->listCode
            )->price_minor
        );
    }

    public function test_rejects_invalid_compare_at_amount(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->set(
            15000,
            14999,
            (string) Str::uuid(),
            0
        );
    }

    private function set(
        int $priceMinor,
        ?int $compareAtMinor,
        string $changeKey,
        int $expectedRevision
    ): int {
        return $this->prices->setPrice(
            $this->variantId,
            $this->listCode,
            $priceMinor,
            $compareAtMinor,
            'PRICE-TEST-001',
            $changeKey,
            $expectedRevision
        );
    }
}
