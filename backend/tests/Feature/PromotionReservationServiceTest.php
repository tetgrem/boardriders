<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Promotions\PromotionReservationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PostgreSQL integration tests.
 * Виконувати тільки на boardriders_test.
 */
final class PromotionReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $promotionId;
    private int $codeId;
    private int $variantId;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->code = 'PROMO-' . strtoupper(Str::random(10));

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $priceListId = (int) DB::table('price_lists')
            ->insertGetId([
                'code' => 'UA_RETAIL',
                'name' => 'UA test retail',
                'country_code' => 'UA',
                'currency_code' => 'UAH',
                'type' => 'retail',
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $productId = (int) DB::table('products')
            ->insertGetId([
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'PROMO-RES-' . Str::uuid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $priceListId,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'PROMO-RES-' . Str::uuid(),
                'name' => 'Promo reservation test',
                'kind' => 'owned',
                'country_code' => 'UA',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 10,
            'reserved_qty' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->promotionId = (int) DB::table('promotions')
            ->insertGetId([
                'price_list_id' => $priceListId,
                'name' => 'Promo reservation test',
                'type' => 'percent',
                'value' => 1000,
                'min_subtotal_minor' => 0,
                'starts_at' => $now->copy()->subDay(),
                'ends_at' => $now->copy()->addDay(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->codeId = (int) DB::table('promotion_codes')
            ->insertGetId([
                'promotion_id' => $this->promotionId,
                'code' => $this->code,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_reserve_uses_live_discount_and_does_not_redeem(): void
    {
        $reserved = $this->reserve($this->order());

        $this->assertSame('reserved', $reserved['status']);
        $this->assertSame(100, $reserved['discount_minor']);
        $this->assertSame('UAH', $reserved['currency_code']);

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $reserved['request_sha256']
        );

        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertDatabaseCount('promotion_redemptions', 0);
        $this->assertDatabaseCount('promotion_reservation_events', 0);
    }

    public function test_identical_reservation_request_is_idempotent(): void
    {
        $orderId = $this->order();
        $key = (string) Str::uuid();
        $expiry = CarbonImmutable::now('UTC')->addMinutes(30);

        $first = $this->reserve($orderId, $key, $expiry);
        $second = $this->reserve($orderId, $key, $expiry);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('promotion_reservations', 1);
    }

    public function test_order_cannot_reuse_reservation_with_changed_request(): void
    {
        $orderId = $this->order();
        $key = (string) Str::uuid();
        $expiry = CarbonImmutable::now('UTC')->addMinutes(30);

        $this->reserve($orderId, $key, $expiry);

        $this->expectException(DomainException::class);

        $this->reserve(
            $orderId,
            $key,
            $expiry,
            null,
            $this->code,
            2
        );
    }

    public function test_promotion_limit_counts_other_pending_reservations(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['usage_limit' => 1]);

        $this->reserve($this->order());

        $this->expectException(DomainException::class);

        $this->reserve($this->order());
    }

    public function test_code_limit_does_not_block_another_code_of_same_promotion(): void
    {
        DB::table('promotion_codes')
            ->where('id', $this->codeId)
            ->update(['usage_limit' => 1]);

        $this->reserve($this->order());

        $secondCode = 'SECOND-' . strtoupper(Str::random(8));

        DB::table('promotion_codes')->insert([
            'promotion_id' => $this->promotionId,
            'code' => $secondCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $second = $this->reserve(
            $this->order(),
            null,
            null,
            null,
            $secondCode
        );

        $this->assertSame(
            $secondCode,
            $second['promotion_code']
        );

        $this->assertDatabaseCount('promotion_reservations', 2);
    }

    public function test_customer_limit_counts_pending_without_blocking_other_users(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['per_customer_limit' => 1]);

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->reserve(
            $this->order(),
            null,
            null,
            $first->id
        );

        try {
            $this->reserve(
                $this->order(),
                null,
                null,
                $first->id
            );

            $this->fail(
                'Same customer bypassed promotion quota'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'limit',
                $exception->getMessage()
            );
        }

        $accepted = $this->reserve(
            $this->order(),
            null,
            null,
            $second->id
        );

        $this->assertSame(
            $second->id,
            $accepted['user_id']
        );
    }

    public function test_guest_cannot_bypass_per_customer_limit(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['per_customer_limit' => 1]);

        $this->expectException(DomainException::class);

        $this->reserve($this->order());
    }

    public function test_release_frees_quota_and_is_idempotent(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['usage_limit' => 1]);

        $first = $this->reserve($this->order());

        $operationKey = (string) Str::uuid();

        $released = $this->service()->release(
            $first['reservation_id'],
            $operationKey
        );

        $this->assertSame('released', $released['status']);

        $this->assertSame(
            $released,
            $this->service()->release(
                $first['reservation_id'],
                $operationKey
            )
        );

        $second = $this->reserve($this->order());

        $this->assertSame('reserved', $second['status']);

        $this->assertDatabaseCount(
            'promotion_reservation_events',
            1
        );

        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_capture_writes_exactly_one_redemption(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['usage_limit' => 1]);

        $orderId = $this->order();
        $reservation = $this->reserve($orderId);

        // Тільки симуляція платіжного статусу для тесту.
        DB::table('orders')
            ->where('id', $orderId)
            ->update(['payment_status' => 'paid']);

        $key = (string) Str::uuid();

        $captured = $this->service()->capture(
            $reservation['reservation_id'],
            $key
        );

        $this->assertSame('captured', $captured['status']);
        $this->assertNotNull($captured['redemption_id']);

        $this->assertSame(
            $captured,
            $this->service()->capture(
                $reservation['reservation_id'],
                $key
            )
        );

        $this->assertDatabaseCount('promotion_redemptions', 1);

        $this->assertDatabaseHas('promotion_redemptions', [
            'order_id' => $orderId,
            'discount_minor' => 100,
        ]);

        // Підтверджене використання продовжує
        // враховуватися в загальному ліміті.
        try {
            $this->reserve($this->order());

            $this->fail(
                'Captured promotion must still count as used'
            );
        } catch (DomainException) {
            $this->assertDatabaseCount(
                'promotion_reservations',
                1
            );
        }
    }

    public function test_expired_reservation_stops_using_quota_but_cannot_be_captured(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['usage_limit' => 1]);

        $orderId = $this->order();
        $expiry = CarbonImmutable::now('UTC')->addMinute();

        $first = $this->reserve(
            $orderId,
            null,
            $expiry
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addMinutes(2)
        );

        $this->assertSame(
            'expired',
            $this->service()->find(
                $first['reservation_id']
            )['status']
        );

        $second = $this->reserve($this->order());

        $this->assertSame('reserved', $second['status']);

        DB::table('orders')
            ->where('id', $orderId)
            ->update(['payment_status' => 'paid']);

        $this->expectException(DomainException::class);

        $this->service()->capture(
            $first['reservation_id'],
            (string) Str::uuid()
        );
    }

    public function test_invalid_promotion_preview_creates_no_reservation(): void
    {
        $orderId = $this->order();

        try {
            $this->reserve(
                $orderId,
                null,
                null,
                null,
                'BAD-CODE'
            );

            $this->fail(
                'Nonexistent promotion must fail'
            );
        } catch (DomainException) {
            $this->assertDatabaseCount(
                'promotion_reservations',
                0
            );

            $this->assertDatabaseCount(
                'promotion_redemptions',
                0
            );
        }
    }

    public function test_paid_order_cannot_create_a_new_promotion_reservation(): void
    {
        $this->expectException(DomainException::class);

        $this->reserve(
            $this->order('paid')
        );
    }

    public function test_capture_requires_paid_order(): void
    {
        $reservation = $this->reserve($this->order());

        $this->expectException(DomainException::class);

        $this->service()->capture(
            $reservation['reservation_id'],
            (string) Str::uuid()
        );
    }

    public function test_paid_order_cannot_release_promotion_reservation(): void
    {
        $orderId = $this->order();
        $reservation = $this->reserve($orderId);

        DB::table('orders')
            ->where('id', $orderId)
            ->update(['payment_status' => 'paid']);

        $this->expectException(DomainException::class);

        $this->service()->release(
            $reservation['reservation_id'],
            (string) Str::uuid()
        );
    }

    public function test_database_rejects_mutation_of_reservation_history(): void
    {
        $reservation = $this->reserve($this->order());

        $this->expectException(QueryException::class);

        DB::table('promotion_reservations')
            ->where('id', $reservation['reservation_id'])
            ->update(['discount_minor' => 1]);
    }

    private function service(): PromotionReservationService
    {
        return app(PromotionReservationService::class);
    }

    private function order(string $status = 'pending'): int
    {
        return (int) DB::table('orders')->insertGetId([
            'order_key' => (string) Str::uuid(),
            'payment_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function reserve(
        int $orderId,
        ?string $key = null,
        ?CarbonImmutable $expires = null,
        ?int $userId = null,
        ?string $code = null,
        int $quantity = 1
    ): array {
        return $this->service()->reserve(
            $orderId,
            $key ?? (string) Str::uuid(),
            $code ?? $this->code,
            [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => $quantity,
                ],
            ],
            'UA_RETAIL',
            $expires ?? CarbonImmutable::now('UTC')->addMinutes(30),
            $userId
        );
    }
}
