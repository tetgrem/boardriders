<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentAttemptService;
use App\Services\Pricing\SandboxCaptureWebhookService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Only boardriders_test.
 * Local synthetic signed messages.
 * No real provider or network requests.
 */
final class SandboxCaptureWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET =
        'LOCAL_ONLY_sandbox_HMAC_key_2026_very_long_77_test_only';

    /** @var array<string,mixed> */
    private array $order;

    /** @var array<string,mixed> */
    private array $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Only PostgreSQL boardriders_test is allowed'
            );
        }

        config()->set(
            'services.boardriders_test_gateway.webhook_secret',
            self::SECRET
        );

        $now = now();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('markets')->insert([
            'code' => 'UA',
            'name' => 'Ukraine',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $listId = (int) DB::table('price_lists')
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

        $variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'SIGNED-CAP-' . Str::uuid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $variantId,
            'price_list_id' => $listId,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouse = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'SIGNED-CAP-WH-' . Str::uuid(),
                'name' => 'Signed capture test warehouse',
                'kind' => 'owned',
                'country_code' => 'UA',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouse,
            'variant_id' => $variantId,
            'on_hand_qty' => 20,
            'reserved_qty' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->order = app(
            CheckoutOrderPreparationService::class
        )->prepare(
            (string) Str::uuid(),
            [
                [
                    'variant_id' => $variantId,
                    'quantity' => 2,
                ],
            ],
            'UA',
            'UA_RETAIL',
            CarbonImmutable::now('UTC')->addMinutes(30),
        );

        $this->attempt = app(
            CheckoutPaymentAttemptService::class
        )->bindPreparedOrder(
            $this->order['order_key'],
            (string) Str::uuid(),
            'test_gateway',
            'pi-' . Str::uuid(),
            CarbonImmutable::now('UTC')->addMinutes(20),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_valid_signed_capture_marks_bound_order_paid(): void
    {
        $response = $this->deliver(
            $this->payload()
        );

        $this->assertSame(
            'paid',
            $response['decision']['decision']
        );

        $this->assertSame(
            'accepted',
            $response['decision']['reason_code']
        );

        $this->assertSame(
            'test_gateway',
            $response['receipt']['provider_code']
        );

        $this->assertSame(
            $this->attempt['provider_payment_id'],
            $response['receipt']['provider_payment_id']
        );

        $this->assertDatabaseHas('orders', [
            'id' => $this->order['order_id'],
            'payment_status' => 'paid',
        ]);

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            1
        );

        $this->assertDatabaseCount(
            'checkout_payment_capture_decisions',
            1
        );
    }

    public function test_identical_signed_delivery_is_idempotent(): void
    {
        $payload = $this->payload();

        $first = $this->deliver($payload);
        $second = $this->deliver($payload);

        $this->assertSame($first, $second);

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            1
        );

        $this->assertDatabaseCount(
            'checkout_payment_capture_decisions',
            1
        );
    }

    public function test_same_event_with_fresh_valid_signature_is_idempotent(): void
    {
        $payload = $this->payload();

        $first = $this->deliver($payload);

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addSeconds(2)
        );

        $second = $this->deliver($payload);

        $this->assertSame($first, $second);

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            1
        );
    }

    public function test_changed_raw_body_with_old_signature_is_rejected(): void
    {
        $payload = $this->payload();

        $original = $this->encodePayload($payload);
        $timestamp = $this->timestamp();

        $signature = $this->signature(
            $timestamp,
            $original
        );

        $payload['amount_minor']++;

        $this->expectException(
            DomainException::class
        );

        $this->service()->handleSignedCapture(
            $this->encodePayload($payload),
            $timestamp,
            $signature
        );
    }

    public function test_signature_from_another_secret_is_rejected(): void
    {
        $raw = $this->encodePayload(
            $this->payload()
        );

        $timestamp = $this->timestamp();

        $forged = 'v1=' . hash_hmac(
                'sha256',
                $timestamp . '.' . $raw,
                str_repeat('X', 48)
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()->handleSignedCapture(
            $raw,
            $timestamp,
            $forged
        );
    }

    public function test_stale_signed_webhook_timestamp_is_rejected(): void
    {
        $raw = $this->encodePayload(
            $this->payload()
        );

        $timestamp = (string) (
            CarbonImmutable::now('UTC')->timestamp - 301
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()->handleSignedCapture(
            $raw,
            $timestamp,
            $this->signature($timestamp, $raw)
        );
    }

    public function test_future_signed_webhook_timestamp_is_rejected(): void
    {
        $raw = $this->encodePayload(
            $this->payload()
        );

        $timestamp = (string) (
            CarbonImmutable::now('UTC')->timestamp + 301
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()->handleSignedCapture(
            $raw,
            $timestamp,
            $this->signature($timestamp, $raw)
        );
    }

    public function test_missing_webhook_secret_fails_closed(): void
    {
        config()->set(
            'services.boardriders_test_gateway.webhook_secret',
            null
        );

        $this->expectException(
            RuntimeException::class
        );

        $this->deliver(
            $this->payload()
        );
    }

    public function test_signed_malformed_json_is_rejected(): void
    {
        $raw = '{"event_type":';

        $timestamp = $this->timestamp();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()->handleSignedCapture(
            $raw,
            $timestamp,
            $this->signature($timestamp, $raw)
        );
    }

    public function test_signed_non_capture_event_is_rejected(): void
    {
        $payload = $this->payload();

        $payload['event_type'] = 'payment.refunded';

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->deliver($payload);
    }

    public function test_unknown_provider_payment_id_cannot_choose_an_order(): void
    {
        $payload = $this->payload();

        $payload['provider_payment_id'] =
            'pi-unknown-' . Str::uuid();

        $this->expectException(
            DomainException::class
        );

        $this->deliver($payload);
    }

    public function test_signed_wrong_amount_is_recorded_but_reviewed(): void
    {
        $payload = $this->payload();

        $payload['amount_minor'] = 1999;

        $response = $this->deliver($payload);

        $this->assertSame(
            'review',
            $response['decision']['decision']
        );

        $this->assertSame(
            'amount_mismatch',
            $response['decision']['reason_code']
        );

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            1
        );

        $this->assertDatabaseCount(
            'checkout_payment_review_cases',
            1
        );

        $this->assertDatabaseHas('orders', [
            'id' => $this->order['order_id'],
            'payment_status' => 'review',
        ]);
    }

    public function test_signed_wrong_currency_is_recorded_but_reviewed(): void
    {
        DB::table('currencies')->insertOrIgnore([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->payload();

        $payload['currency_code'] = 'EUR';

        $response = $this->deliver($payload);

        $this->assertSame(
            'review',
            $response['decision']['decision']
        );

        $this->assertSame(
            'currency_mismatch',
            $response['decision']['reason_code']
        );

        $this->assertDatabaseCount(
            'checkout_payment_review_cases',
            1
        );
    }

    public function test_signed_client_order_key_override_is_not_allowed(): void
    {
        $payload = $this->payload();

        $payload['order_key'] = (string) Str::uuid();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->deliver($payload);
    }

    public function test_signed_amount_as_string_is_not_accepted(): void
    {
        $payload = $this->payload();

        $payload['amount_minor'] = '2000';

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->deliver($payload);
    }

    public function test_same_capture_id_with_different_event_id_is_rejected(): void
    {
        $payload = $this->payload();

        $this->deliver($payload);

        $payload['event_id'] = 'evt-' . Str::uuid();

        $this->expectException(
            DomainException::class
        );

        $this->deliver($payload);
    }

    // ========================================
    // ДОПОМІЖНІ МЕТОДИ
    // ========================================

    /**
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return [
            'event_type' => 'payment.captured',
            'event_id' => 'evt-' . Str::uuid(),
            'capture_id' => 'cap-' . Str::uuid(),
            'provider_payment_id' =>
                $this->attempt['provider_payment_id'],
            'amount_minor' =>
                $this->attempt['amount_minor'],
            'currency_code' =>
                $this->attempt['currency_code'],
            'captured_at' =>
                CarbonImmutable::now('UTC')
                    ->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array{
     *     receipt:array<string,mixed>,
     *     decision:array<string,mixed>
     * }
     */
    private function deliver(array $payload): array
    {
        $raw = $this->encodePayload($payload);

        $timestamp = $this->timestamp();

        return $this->service()->handleSignedCapture(
            $raw,
            $timestamp,
            $this->signature($timestamp, $raw)
        );
    }

    /**
     * Encode payload to JSON for signed webhook testing.
     *
     * Важливо: не називати метод json(),
     * оскільки такий публічний метод уже існує
     * у базовому Laravel TestCase.
     *
     * @param array<string,mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );
    }

    private function timestamp(): string
    {
        return (string) CarbonImmutable::now('UTC')->timestamp;
    }

    private function signature(
        string $timestamp,
        string $raw
    ): string {
        return 'v1=' . hash_hmac(
                'sha256',
                $timestamp . '.' . $raw,
                self::SECRET
            );
    }

    private function service(): SandboxCaptureWebhookService
    {
        return app(SandboxCaptureWebhookService::class);
    }
}
