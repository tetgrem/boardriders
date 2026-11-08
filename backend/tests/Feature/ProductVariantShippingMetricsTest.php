<?php

namespace Tests\Feature;

use App\Services\Pricing\ProductVariantShippingMetricsService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class ProductVariantShippingMetricsTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'SHIP-' . Str::uuid(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function change(
        ?string $key = null,
        int $revision = 0,
        ?int $weight = 350,
        ?int $length = 200,
        ?int $width = 100,
        ?int $height = 50,
    ): array {
        return app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId, $key ?? (string) Str::uuid(), $revision,
            $weight, $length, $width, $height
        );
    }

    public function test_saves_metrics_and_append_only_history(): void
    {
        $saved = $this->change();
        $this->assertSame(1, $saved['new_revision']);
        $this->assertSame(350, $saved['current']['weight_grams']);
        $this->assertNull($saved['previous']['weight_grams']);
        $this->assertDatabaseHas('product_variants', [
            'id' => $this->variantId, 'weight_grams' => 350,
            'shipping_length_mm' => 200, 'shipping_width_mm' => 100,
            'shipping_height_mm' => 50, 'shipping_measurement_revision' => 1,
        ]);
        $this->assertDatabaseCount('product_variant_shipping_measurement_changes', 1);
    }

    public function test_retry_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $this->assertSame($this->change($key), $this->change($key));
        $this->assertDatabaseCount('product_variant_shipping_measurement_changes', 1);
    }

    public function test_change_key_cannot_change_content(): void
    {
        $key = (string) Str::uuid();
        $this->change($key);
        $this->expectException(DomainException::class);
        $this->change($key, weight: 450);
    }

    public function test_revision_conflict_is_rejected(): void
    {
        $this->change();
        $this->expectException(DomainException::class);
        $this->change(revision: 0, weight: 600);
    }

    public function test_second_revision_keeps_history(): void
    {
        $this->change();
        $second = $this->change(revision: 1, weight: 700);
        $this->assertSame(350, $second['previous']['weight_grams']);
        $this->assertSame(700, $second['current']['weight_grams']);
        $this->assertDatabaseCount('product_variant_shipping_measurement_changes', 2);
    }

    public function test_partial_dimensions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->change(height: null);
    }

    public function test_missing_weight_with_dimensions_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->change(weight: null);
    }

    public function test_database_rejects_partial_dimensions(): void
    {
        $this->expectException(QueryException::class);
        DB::table('product_variants')->where('id', $this->variantId)->update([
            'shipping_length_mm' => 30,
        ]);
    }

    public function test_measurement_history_is_immutable_in_database(): void
    {
        $saved = $this->change();
        $this->expectException(QueryException::class);
        DB::table('product_variant_shipping_measurement_changes')
            ->where('id', $saved['id'])->delete();
    }
}
