<?php

namespace Tests\Feature;

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

/**
 * Немає викликів реального провайдера: тестуємо лише trusted service boundary.
 * Тільки PostgreSQL boardriders_test. Жодного migrate:fresh.
 */
final class CheckoutPaymentCaptureReceiptTest extends TestCase
{
    use DatabaseTransactions;

    private string $orderKey;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException('Only PostgreSQL boardriders_test is allowed');
        }

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->orderKey = $this->order();
    }

    public function test_recording_capture_does_not_mark_order_paid_or_consume_reserves(): void
    {
        $receipt = $this->record();

        $this->assertSame('recorded', $receipt['status']);
        $this->assertSame('demo_gateway', $receipt['provider_code']);
        $this->assertSame('charge-001', $receipt['provider_capture_id']);
        $this->assertSame(2500, $receipt['amount_minor']);
        $this->assertSame('UAH', $receipt['currency_code']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $receipt['sha256']);

        $this->assertDatabaseHas('orders', [
            'order_key' => $this->orderKey,
            'payment_status' => 'pending',
        ]);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 1);
        $this->assertDatabaseCount('promotion_redemptions', 0);
        $this->assertDatabaseCount('loyalty_reservations', 0);
    }

    public function test_identical_verified_event_is_idempotent(): void
    {
        $capturedAt = CarbonImmutable::now('UTC');
        $first = $this->record(capturedAt: $capturedAt);
        $again = $this->record(capturedAt: $capturedAt);

        $this->assertSame($first, $again);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 1);
    }

    public function test_reused_capture_id_with_changed_amount_is_rejected(): void
    {
        $when = CarbonImmutable::now('UTC');
        $this->record(capturedAt: $when);
        $this->expectException(DomainException::class);
        $this->record(amount: 2501, capturedAt: $when);
    }

    public function test_reused_capture_id_with_changed_currency_is_rejected(): void
    {
        DB::table('currencies')->insertOrIgnore([
            'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€',
            'minor_units' => 2, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $when = CarbonImmutable::now('UTC');
        $this->record(capturedAt: $when);
        $this->expectException(DomainException::class);
        $this->record(currency: 'EUR', capturedAt: $when);
    }

    public function test_reused_capture_id_with_changed_timestamp_is_rejected(): void
    {
        $when = CarbonImmutable::now('UTC');
        $this->record(capturedAt: $when);
        $this->expectException(DomainException::class);
        $this->record(capturedAt: $when->subSecond());
    }

    public function test_capture_id_with_another_event_id_cannot_duplicate_receipt(): void
    {
        $when = CarbonImmutable::now('UTC');
        $this->record(capturedAt: $when);
        $this->expectException(DomainException::class);
        $this->record(eventId: 'evt-002', capturedAt: $when);
    }

    public function test_event_id_with_another_capture_id_is_rejected(): void
    {
        $when = CarbonImmutable::now('UTC');
        $this->record(capturedAt: $when);
        $this->expectException(DomainException::class);
        $this->record(captureId: 'charge-002', capturedAt: $when);
    }

    public function test_capture_id_cannot_be_used_for_another_order(): void
    {
        $when = CarbonImmutable::now('UTC');
        $this->record(capturedAt: $when);
        $this->expectException(DomainException::class);
        $this->record(orderKey: $this->order(), capturedAt: $when);
    }

    public function test_canceled_order_can_have_a_late_capture_receipt_without_reopening(): void
    {
        DB::table('orders')
            ->where('order_key', $this->orderKey)
            ->update(['payment_status' => 'canceled']);

        $receipt = $this->record();

        $this->assertSame('recorded', $receipt['status']);
        $this->assertDatabaseHas('orders', [
            'order_key' => $this->orderKey,
            'payment_status' => 'canceled',
        ]);
    }

    public function test_unknown_order_is_rejected_without_inbox_row(): void
    {
        try {
            $this->record(orderKey: (string) Str::uuid());
            $this->fail('Unknown order must fail');
        } catch (DomainException) {
            $this->assertDatabaseCount('checkout_payment_capture_receipts', 0);
        }
    }

    public function test_invalid_provider_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->record(provider: 'not permitted!');
    }

    public function test_far_future_capture_timestamp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->record(capturedAt: CarbonImmutable::now('UTC')->addHour());
    }

    public function test_database_blocks_modifying_recorded_receipt(): void
    {
        $receipt = $this->record();
        $this->expectException(QueryException::class);
        DB::table('checkout_payment_capture_receipts')
            ->where('id', $receipt['id'])
            ->update(['amount_minor' => 1]);
    }

    public function test_database_rejects_incorrect_capture_checksum(): void
    {
        $this->expectException(QueryException::class);
        DB::table('checkout_payment_capture_receipts')->insert([
            'order_id' => DB::table('orders')
                ->where('order_key', $this->orderKey)->value('id'),
            'provider_code' => 'demo_gateway',
            'provider_event_id' => 'evt-bad',
            'provider_capture_id' => 'charge-bad',
            'amount_minor' => 2500,
            'currency_code' => 'UAH',
            'captured_at' => CarbonImmutable::now('UTC'),
            'capture_json' => '{"amount_minor":2500}',
            'capture_sha256' => str_repeat('0', 64),
        ]);
    }

    public function test_same_external_id_from_different_provider_is_distinct(): void
    {
        $when = CarbonImmutable::now('UTC');
        $first = $this->record(capturedAt: $when);
        $second = $this->record(provider: 'another_gateway', capturedAt: $when);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 2);
        $this->assertSame(
            $first,
            $this->service()->findByProviderCapture('demo_gateway', 'charge-001')
        );
    }

    private function service(): CheckoutPaymentCaptureReceiptService
    {
        return app(CheckoutPaymentCaptureReceiptService::class);
    }

    private function order(): string
    {
        $key = (string) Str::uuid();
        DB::table('orders')->insert([
            'order_key' => $key,
            'payment_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $key;
    }

    /** @return array<string,mixed> */
    private function record(
        string $provider = 'demo_gateway',
        string $eventId = 'evt-001',
        string $captureId = 'charge-001',
        ?string $orderKey = null,
        int $amount = 2500,
        string $currency = 'UAH',
        ?CarbonImmutable $capturedAt = null,
    ): array {
        return $this->service()->recordVerifiedCapture(
            $provider,
            $eventId,
            $captureId,
            $orderKey ?? $this->orderKey,
            $amount,
            $currency,
            $capturedAt ?? CarbonImmutable::now('UTC')
        );
    }
}
