<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentAttemptService;
use App\Services\Pricing\CheckoutPaymentCaptureReceiptService;
use App\Services\Pricing\CheckoutPaymentFinalizationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** PostgreSQL boardriders_test only; no payment provider calls. */
final class CheckoutPaymentAttemptVerificationTest extends TestCase
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
            'code' => 'UA_RETAIL', 'name' => 'UA retail', 'country_code' => 'UA',
            'currency_code' => 'UAH', 'type' => 'retail',
            'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'VERIFY-ATTEMPT-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $priceListId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'VERIFY-WH-' . Str::uuid(), 'name' => 'Attempt verification test',
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

    public function test_matching_bound_capture_can_finalize_to_paid(): void
    {
        $order = $this->prepare();
        $attempt = $this->bind($order);
        $receipt = $this->record($order, $attempt['provider_payment_id']);
        $decision = $this->finalize($receipt);

        $this->assertSame('paid', $decision['decision']);
        $this->assertSame('accepted', $decision['reason_code']);
        $this->assertDatabaseCount('checkout_payment_attempts', 1);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 1);
        $this->assertDatabaseCount('checkout_payment_review_cases', 0);
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'reserved_qty' => 2,
        ]);
    }

    public function test_prepared_order_without_bound_attempt_is_reviewed(): void
    {
        $order = $this->prepare();
        $receipt = $this->record($order, 'pi-unbound-' . Str::uuid());
        $decision = $this->finalize($receipt);

        $this->assertReview($decision, 'payment_attempt_missing');
        $this->assertDatabaseCount('checkout_payment_attempts', 0);
        $this->assertDatabaseCount('checkout_payment_review_cases', 1);
    }

    public function test_legacy_capture_without_provider_payment_id_is_reviewed(): void
    {
        $order = $this->prepare();
        $this->bind($order);
        $decision = $this->finalize($this->record($order, null));

        $this->assertReview($decision, 'provider_payment_id_missing');
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 1);
    }

    public function test_different_provider_payment_id_is_reviewed(): void
    {
        $order = $this->prepare();
        $this->bind($order);
        $decision = $this->finalize($this->record($order, 'pi-different-' . Str::uuid()));

        $this->assertReview($decision, 'payment_attempt_mismatch');
    }

    public function test_different_provider_code_is_reviewed(): void
    {
        $order = $this->prepare();
        $attempt = $this->bind($order);
        $decision = $this->finalize($this->record(
            $order, $attempt['provider_payment_id'], 'other_gateway'
        ));

        $this->assertReview($decision, 'payment_attempt_mismatch');
    }

    public function test_provider_payment_id_bound_to_another_order_cannot_pay_this_order(): void
    {
        $first = $this->prepare();
        $second = $this->prepare();
        $firstAttempt = $this->bind($first);
        $this->bind($second);

        $decision = $this->finalize($this->record(
            $second, $firstAttempt['provider_payment_id']
        ));

        $this->assertReview($decision, 'payment_attempt_mismatch');
        $this->assertDatabaseHas('orders', [
            'id' => $first['order_id'], 'payment_status' => 'pending',
        ]);
    }

    public function test_payment_capture_after_attempt_expiry_is_reviewed(): void
    {
        $start = CarbonImmutable::now('UTC');
        $order = $this->prepare();
        $attempt = $this->bind($order, $start->addMinutes(3));
        CarbonImmutable::setTestNow($start->addMinutes(4));

        $decision = $this->finalize($this->record(
            $order, $attempt['provider_payment_id'], 'test_gateway',
            $start->addMinutes(4)
        ));

        $this->assertReview($decision, 'capture_outside_attempt_window');
    }

    public function test_recorded_bound_receipt_is_idempotent(): void
    {
        $order = $this->prepare();
        $attempt = $this->bind($order);
        $when = CarbonImmutable::now('UTC');
        $event = 'evt-' . Str::uuid();
        $capture = 'cap-' . Str::uuid();

        $first = app(CheckoutPaymentCaptureReceiptService::class)->recordVerifiedCapture(
            'test_gateway', $event, $capture, $order['order_key'],
            2000, 'UAH', $when, $attempt['provider_payment_id']
        );
        $second = app(CheckoutPaymentCaptureReceiptService::class)->recordVerifiedCapture(
            'test_gateway', $event, $capture, $order['order_key'],
            2000, 'UAH', $when, $attempt['provider_payment_id']
        );

        $this->assertSame($first, $second);
        $this->assertSame($attempt['provider_payment_id'], $first['provider_payment_id']);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 1);
    }

    public function test_capture_key_cannot_be_replayed_with_other_provider_payment_id(): void
    {
        $order = $this->prepare();
        $attempt = $this->bind($order);
        $when = CarbonImmutable::now('UTC');
        $event = 'evt-' . Str::uuid();
        $capture = 'cap-' . Str::uuid();
        $service = app(CheckoutPaymentCaptureReceiptService::class);

        $service->recordVerifiedCapture(
            'test_gateway', $event, $capture, $order['order_key'],
            2000, 'UAH', $when, $attempt['provider_payment_id']
        );
        $this->expectException(DomainException::class);
        $service->recordVerifiedCapture(
            'test_gateway', $event, $capture, $order['order_key'],
            2000, 'UAH', $when, 'pi-replaced-' . Str::uuid()
        );
    }

    public function test_database_rejects_provider_payment_id_not_matching_capture_json(): void
    {
        $order = $this->prepare();
        $this->bind($order);
        $when = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
        $payload = [
            'schema_version' => 1,
            'provider_code' => 'test_gateway',
            'provider_event_id' => 'evt-' . Str::uuid(),
            'provider_capture_id' => 'cap-' . Str::uuid(),
            'order_key' => $order['order_key'],
            'amount_minor' => 2000,
            'currency_code' => 'UAH',
            'captured_at' => $when,
            'provider_payment_id' => 'pi-from-json',
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->expectException(QueryException::class);
        DB::table('checkout_payment_capture_receipts')->insert([
            'order_id' => $order['order_id'],
            'provider_code' => $payload['provider_code'],
            'provider_event_id' => $payload['provider_event_id'],
            'provider_capture_id' => $payload['provider_capture_id'],
            'provider_payment_id' => 'pi-different-column',
            'amount_minor' => 2000,
            'currency_code' => 'UAH',
            'captured_at' => $when,
            'capture_json' => $json,
            'capture_sha256' => hash('sha256', $json),
        ]);
    }

    private function prepare(): array
    {
        return app(CheckoutOrderPreparationService::class)->prepare(
            (string) Str::uuid(),
            [['variant_id' => $this->variantId, 'quantity' => 2]],
            'UA', 'UA_RETAIL', CarbonImmutable::now('UTC')->addMinutes(30)
        );
    }

    private function bind(array $order, ?CarbonImmutable $expires = null): array
    {
        return app(CheckoutPaymentAttemptService::class)->bindPreparedOrder(
            $order['order_key'], (string) Str::uuid(),
            'test_gateway', 'pi-' . Str::uuid(),
            $expires ?? CarbonImmutable::parse($order['expires_at'])
        );
    }

    private function record(
        array $order,
        ?string $providerPaymentId,
        string $providerCode = 'test_gateway',
        ?CarbonImmutable $capturedAt = null,
    ): int {
        $receipt = app(CheckoutPaymentCaptureReceiptService::class)->recordVerifiedCapture(
            $providerCode, 'evt-' . Str::uuid(), 'cap-' . Str::uuid(),
            $order['order_key'], 2000, 'UAH',
            $capturedAt ?? CarbonImmutable::now('UTC'),
            $providerPaymentId
        );
        return $receipt['id'];
    }

    private function finalize(int $receiptId): array
    {
        return app(CheckoutPaymentFinalizationService::class)->finalize($receiptId);
    }

    private function assertReview(array $decision, string $reason): void
    {
        $this->assertSame('review', $decision['decision']);
        $this->assertSame($reason, $decision['reason_code']);
        $this->assertSame('review', $decision['order_payment_status']);
        $this->assertDatabaseCount('promotion_redemptions', 0);
        $this->assertDatabaseCount('checkout_payment_review_cases', 1);
    }
}
