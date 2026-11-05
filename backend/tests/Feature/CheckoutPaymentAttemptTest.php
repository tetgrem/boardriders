<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentAttemptService;
use App\Services\Pricing\CheckoutPaymentCaptureReceiptService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** Інтеграція виключно з PostgreSQL boardriders_test. Без зовнішнього провайдера. */
final class CheckoutPaymentAttemptTest extends TestCase
{
    use DatabaseTransactions;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException('Only PostgreSQL boardriders_test is allowed');
        }

        $now = now();
        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH', 'name' => 'Ukrainian hryvnia', 'symbol' => '₴',
            'minor_units' => 2, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('markets')->insert([
            'code' => 'UA', 'name' => 'Ukraine', 'country_code' => 'UA',
            'currency_code' => 'UAH', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $priceListId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL', 'name' => 'UA retail',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'ATTEMPT-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $priceListId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'ATTEMPT-WH-' . Str::uuid(), 'name' => 'Attempt test warehouse',
            'country_code' => 'UA', 'kind' => 'owned', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId, 'variant_id' => $this->variantId,
            'on_hand_qty' => 20, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_binds_provider_intent_to_exact_pending_order_snapshot(): void
    {
        $order = $this->prepared();
        $attempt = $this->bind($order);

        $this->assertSame($order['order_id'], $attempt['order_id']);
        $this->assertSame($order['order_key'], $attempt['order_key']);
        $this->assertSame('bound', $attempt['status']);
        $this->assertSame(2000, $attempt['amount_minor']);
        $this->assertSame('UAH', $attempt['currency_code']);
        $this->assertSame(
            $order['financial_snapshot']['sha256'],
            $attempt['financial_snapshot_sha256']
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $attempt['request_sha256']);
        $this->assertDatabaseCount('checkout_payment_attempts', 1);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 0);
        $this->assertDatabaseHas('orders', [
            'id' => $order['order_id'], 'payment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'reserved_qty' => 2,
        ]);
    }

    public function test_lookup_by_order_and_provider_ref_returns_same_binding(): void
    {
        $order = $this->prepared();
        $attempt = $this->bind($order, providerPaymentId: 'pi-lookup-' . Str::uuid());
        $this->assertSame($attempt, $this->service()->findForOrder($order['order_id']));
        $this->assertSame($attempt, $this->service()->findByProviderPayment(
            'test_gateway', $attempt['provider_payment_id']
        ));
    }

    public function test_identical_bind_request_is_idempotent(): void
    {
        $order = $this->prepared();
        $key = (string) Str::uuid();
        $providerId = 'pi-' . Str::uuid();
        $expiry = CarbonImmutable::now('UTC')->addMinutes(20);
        $first = $this->bind($order, $key, $providerId, $expiry);
        $second = $this->bind($order, $key, $providerId, $expiry);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_payment_attempts', 1);
    }

    public function test_same_order_cannot_swap_to_another_payment_intent(): void
    {
        $order = $this->prepared();
        $key = (string) Str::uuid();
        $expiry = CarbonImmutable::now('UTC')->addMinutes(20);
        $this->bind($order, $key, 'pi-first-' . Str::uuid(), $expiry);
        $this->expectException(DomainException::class);
        $this->bind($order, $key, 'pi-changed-' . Str::uuid(), $expiry);
    }

    public function test_attempt_uuid_cannot_be_reused_for_another_order(): void
    {
        $firstOrder = $this->prepared();
        $secondOrder = $this->prepared();
        $key = (string) Str::uuid();
        $this->bind($firstOrder, $key);
        $this->expectException(DomainException::class);
        $this->bind($secondOrder, $key);
    }

    public function test_provider_payment_identifier_cannot_be_shared_across_orders(): void
    {
        $firstOrder = $this->prepared();
        $secondOrder = $this->prepared();
        $providerId = 'pi-unique-' . Str::uuid();
        $this->bind($firstOrder, providerPaymentId: $providerId);
        $this->expectException(DomainException::class);
        $this->bind($secondOrder, providerPaymentId: $providerId);
    }

    public function test_order_without_checkout_preparation_cannot_bind_attempt(): void
    {
        $key = (string) Str::uuid();
        DB::table('orders')->insert([
            'order_key' => $key, 'payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(DomainException::class);
        $this->service()->bindPreparedOrder(
            $key, (string) Str::uuid(), 'test_gateway',
            'pi-' . Str::uuid(), CarbonImmutable::now('UTC')->addMinutes(15)
        );
    }

    public function test_paid_order_cannot_create_new_attempt(): void
    {
        $order = $this->prepared();
        DB::table('orders')->where('id', $order['order_id'])
            ->update(['payment_status' => 'paid']);
        $this->expectException(DomainException::class);
        $this->bind($order);
    }

    public function test_canceled_order_cannot_create_new_attempt(): void
    {
        $order = $this->prepared();
        DB::table('orders')->where('id', $order['order_id'])
            ->update(['payment_status' => 'canceled']);
        $this->expectException(DomainException::class);
        $this->bind($order);
    }

    public function test_attempt_cannot_outlive_preparation_reservations(): void
    {
        $order = $this->prepared();
        $this->expectException(DomainException::class);
        $this->bind($order, expiry: CarbonImmutable::now('UTC')->addMinutes(31));
    }

    public function test_expired_attempt_window_is_rejected(): void
    {
        $order = $this->prepared();
        $this->expectException(InvalidArgumentException::class);
        $this->bind($order, expiry: CarbonImmutable::now('UTC')->subMinute());
    }

    public function test_payment_intent_cannot_be_bound_after_a_capture_receipt(): void
    {
        $order = $this->prepared();
        app(CheckoutPaymentCaptureReceiptService::class)->recordVerifiedCapture(
            'test_gateway', 'evt-' . Str::uuid(), 'cap-' . Str::uuid(),
            $order['order_key'], 2000, 'UAH', CarbonImmutable::now('UTC')
        );
        $this->expectException(DomainException::class);
        $this->bind($order);
    }

    public function test_database_blocks_updating_attempt(): void
    {
        $attempt = $this->bind($this->prepared());
        $this->expectException(QueryException::class);
        DB::table('checkout_payment_attempts')->where('id', $attempt['id'])
            ->update(['amount_minor' => 1999]);
    }

    public function test_database_blocks_deleting_attempt(): void
    {
        $attempt = $this->bind($this->prepared());
        $this->expectException(QueryException::class);
        DB::table('checkout_payment_attempts')->where('id', $attempt['id'])->delete();
    }

    public function test_database_rejects_invalid_request_checksum(): void
    {
        $order = $this->prepared();
        $this->expectException(QueryException::class);
        DB::table('checkout_payment_attempts')->insert([
            'attempt_key' => (string) Str::uuid(),
            'order_id' => $order['order_id'],
            'financial_snapshot_id' => $order['financial_snapshot']['id'],
            'provider_code' => 'test_gateway',
            'provider_payment_id' => 'pi-invalid-' . Str::uuid(),
            'amount_minor' => 2000,
            'currency_code' => 'UAH',
            'financial_snapshot_sha256' => $order['financial_snapshot']['sha256'],
            'request_json' => '{"schema_version":1}',
            'request_sha256' => str_repeat('0', 64),
            'expires_at' => CarbonImmutable::now('UTC')->addMinutes(20),
        ]);
    }

    public function test_direct_sql_cannot_bind_different_amount_with_valid_checksum(): void
    {
        $order = $this->prepared();
        $expiry = CarbonImmutable::now('UTC')->addMinutes(20)->format('Y-m-d H:i:s.uP');
        $attemptKey = (string) Str::uuid();
        $providerId = 'pi-mismatch-' . Str::uuid();
        $json = json_encode([
            'schema_version' => 1,
            'attempt_key' => $attemptKey,
            'order_key' => $order['order_key'],
            'order_id' => $order['order_id'],
            'financial_snapshot_id' => $order['financial_snapshot']['id'],
            'provider_code' => 'test_gateway',
            'provider_payment_id' => $providerId,
            'amount_minor' => 1999,
            'currency_code' => 'UAH',
            'financial_snapshot_sha256' => $order['financial_snapshot']['sha256'],
            'expires_at' => $expiry,
        ], JSON_THROW_ON_ERROR);

        $this->expectException(QueryException::class);
        DB::table('checkout_payment_attempts')->insert([
            'attempt_key' => $attemptKey,
            'order_id' => $order['order_id'],
            'financial_snapshot_id' => $order['financial_snapshot']['id'],
            'provider_code' => 'test_gateway',
            'provider_payment_id' => $providerId,
            'amount_minor' => 1999,
            'currency_code' => 'UAH',
            'financial_snapshot_sha256' => $order['financial_snapshot']['sha256'],
            'request_json' => $json,
            'request_sha256' => hash('sha256', $json),
            'expires_at' => $expiry,
        ]);
    }

    private function service(): CheckoutPaymentAttemptService
    {
        return app(CheckoutPaymentAttemptService::class);
    }

    /** @return array<string,mixed> */
    private function prepared(): array
    {
        return app(CheckoutOrderPreparationService::class)->prepare(
            (string) Str::uuid(),
            [['variant_id' => $this->variantId, 'quantity' => 2]],
            'UA', 'UA_RETAIL', CarbonImmutable::now('UTC')->addMinutes(30)
        );
    }

    /** @param array<string,mixed> $order
     *  @return array<string,mixed>
     */
    private function bind(
        array $order,
        ?string $attemptKey = null,
        ?string $providerPaymentId = null,
        ?CarbonImmutable $expiry = null,
    ): array {
        return $this->service()->bindPreparedOrder(
            $order['order_key'],
            $attemptKey ?? (string) Str::uuid(),
            'test_gateway',
            $providerPaymentId ?? 'pi-' . Str::uuid(),
            $expiry ?? CarbonImmutable::now('UTC')->addMinutes(20)
        );
    }
}
