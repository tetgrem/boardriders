<?php

namespace Tests\Feature;

use App\Services\Pricing\OrderFinancialSnapshotService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PostgreSQL integration tests. Execute only against boardriders_test.
 */
final class OrderFinancialSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_stores_and_reads_exact_financial_snapshot(): void
    {
        $orderId = $this->order();
        $saved = $this->service()->store($orderId, $this->quote());

        $this->assertSame($orderId, $saved['order_id']);
        $this->assertSame('UAH', $saved['currency_code']);
        $this->assertSame(199, $saved['goods_payable_minor']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $saved['sha256']);
        $this->assertSame('SAVE1', $saved['snapshot']['promotion_code']);
        $this->assertSame(
            [99, 100],
            array_column($saved['snapshot']['lines'][0]['units'], 'cash_minor')
        );
        $this->assertSame($saved, $this->service()->findForOrder($orderId));
        $this->assertSame(1, DB::table('order_financial_snapshots')->count());
    }

    public function test_identical_store_is_idempotent(): void
    {
        $orderId = $this->order();
        $first = $this->service()->store($orderId, $this->quote());
        $second = $this->service()->store($orderId, $this->quote());

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('order_financial_snapshots')->count());
    }

    public function test_different_snapshot_for_existing_order_is_rejected(): void
    {
        $orderId = $this->order();
        $this->service()->store($orderId, $this->quote());

        $changed = $this->quote();
        $changed['promotion']['code'] = 'SAVE2';

        try {
            $this->service()->store($orderId, $changed);
            $this->fail('Different snapshots must be rejected');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('order_financial_snapshots')->count());
        $this->assertSame('SAVE1',
            $this->service()->findForOrder($orderId)['snapshot']['promotion_code']
        );
    }

    public function test_new_snapshot_cannot_be_added_to_paid_order(): void
    {
        $this->expectException(DomainException::class);
        $this->service()->store($this->order('paid'), $this->quote());
    }

    public function test_new_snapshot_cannot_be_added_to_review_order(): void
    {
        $this->expectException(DomainException::class);
        $this->service()->store($this->order('review'), $this->quote());
    }

    public function test_missing_order_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->service()->store(999999, $this->quote());
    }

    public function test_invalid_input_cannot_create_snapshot(): void
    {
        $orderId = $this->order();
        $quote = $this->quote();
        $quote['goods_payable_minor'] = 198;

        try {
            $this->service()->store($orderId, $quote);
            $this->fail('Inconsistent quote should be rejected');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('reconcile', $exception->getMessage());
        }

        $this->assertNull($this->service()->findForOrder($orderId));
        $this->assertSame(0, DB::table('order_financial_snapshots')->count());
    }

    public function test_database_blocks_snapshot_update(): void
    {
        $orderId = $this->order();
        $this->service()->store($orderId, $this->quote());
        $this->expectException(QueryException::class);

        DB::table('order_financial_snapshots')
            ->where('order_id', $orderId)
            ->update(['goods_payable_minor' => 200]);
    }

    public function test_database_blocks_snapshot_delete(): void
    {
        $orderId = $this->order();
        $this->service()->store($orderId, $this->quote());
        $this->expectException(QueryException::class);

        DB::table('order_financial_snapshots')
            ->where('order_id', $orderId)
            ->delete();
    }

    public function test_database_blocks_snapshot_truncate(): void
    {
        $orderId = $this->order();
        $this->service()->store($orderId, $this->quote());
        $this->expectException(QueryException::class);

        DB::statement('TRUNCATE TABLE order_financial_snapshots');
    }

    public function test_database_rejects_incorrect_checksum_on_insert(): void
    {
        $orderId = $this->order();
        $this->expectException(QueryException::class);

        DB::table('order_financial_snapshots')->insert([
            'order_id' => $orderId,
            'schema_version' => 1,
            'currency_code' => 'UAH',
            'goods_payable_minor' => 199,
            'snapshot_json' => '{"schema_version":1,"currency_code":"UAH","goods_payable_minor":199}',
            'snapshot_sha256' => str_repeat('0', 64),
        ]);
    }

    public function test_different_orders_have_independent_snapshots(): void
    {
        $firstId = $this->order();
        $secondId = $this->order();

        $first = $this->service()->store($firstId, $this->quote());
        $second = $this->service()->store($secondId, $this->quote());

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($first['sha256'], $second['sha256']);
        $this->assertSame(2, DB::table('order_financial_snapshots')->count());
    }

    private function service(): OrderFinancialSnapshotService
    {
        return app(OrderFinancialSnapshotService::class);
    }

    private function order(string $paymentStatus = 'pending'): int
    {
        return (int) DB::table('orders')->insertGetId([
            'order_key' => (string) Str::uuid(),
            'payment_status' => $paymentStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function quote(): array
    {
        return [
            'informational_only' => true,
            'market_code' => 'UA',
            'price_list_code' => 'UA_RETAIL',
            'currency_code' => 'UAH',
            'subtotal_minor' => 200,
            'promotion_discount_minor' => 1,
            'loyalty_discount_minor' => 0,
            'goods_payable_minor' => 199,
            'eligible_goods_after_promotions_minor' => 0,
            'price_revisions' => [11 => 1],
            'lines' => [[
                'variant_id' => 11,
                'quantity' => 2,
                'unit_price_minor' => 100,
                'subtotal_minor' => 200,
                'promotion_discount_minor' => 1,
                'loyalty_discount_minor' => 0,
                'goods_payable_minor' => 199,
                'loyalty_eligible' => false,
            ]],
            'promotion' => [
                'valid' => true,
                'code' => 'SAVE1',
                'discount_minor' => 1,
            ],
            'loyalty' => ['applied_units' => 0],
            'loyalty_eligibility' => null,
        ];
    }
}
