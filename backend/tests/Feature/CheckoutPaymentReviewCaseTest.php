<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutPaymentCaptureReceiptService;
use App\Services\Pricing\CheckoutPaymentFinalizationService;
use App\Services\Pricing\CheckoutPaymentReviewCaseService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class CheckoutPaymentReviewCaseTest extends TestCase
{
    use DatabaseTransactions;

    private int $orderId;
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

        $this->orderKey = (string) Str::uuid();
        $this->orderId = (int) DB::table('orders')->insertGetId([
            'order_key' => $this->orderKey,
            'payment_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_finalization_automatically_opens_review_case_for_missing_preparation(): void
    {
        $receiptId = $this->receipt();
        $final = app(CheckoutPaymentFinalizationService::class)->finalize($receiptId);

        $this->assertSame('review', $final['decision']);
        $this->assertSame('preparation_missing', $final['reason_code']);
        $this->assertSame('review', $final['order_payment_status']);

        $case = $this->cases()->findForDecision($final['decision_id']);
        $this->assertNotNull($case);
        $this->assertSame('missing_checkout_context', $case['case_type']);
        $this->assertSame('investigate', $case['suggested_action']);
        $this->assertSame(2500, $case['captured_amount_minor']);
        $this->assertNull($case['expected_amount_minor']);
        $this->assertDatabaseCount('checkout_payment_review_cases', 1);
    }

    public function test_repeated_finalization_does_not_open_duplicate_review_case(): void
    {
        $receiptId = $this->receipt();
        $finalizer = app(CheckoutPaymentFinalizationService::class);

        $first = $finalizer->finalize($receiptId);
        $second = $finalizer->finalize($receiptId);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_payment_review_cases', 1);
        $this->assertDatabaseCount('checkout_payment_capture_decisions', 1);
    }

    public function test_open_case_twice_is_idempotent(): void
    {
        $decisionId = $this->decision('stock_mismatch');

        $first = $this->cases()->ensureForDecision($decisionId);
        $second = $this->cases()->ensureForDecision($decisionId);

        $this->assertSame($first, $second);
        $this->assertSame('reservation_conflict', $first['case_type']);
        $this->assertSame('reconcile_fulfillment', $first['suggested_action']);
        $this->assertDatabaseCount('checkout_payment_review_cases', 1);
    }

    public function test_another_capture_of_paid_order_is_flagged_for_refund_assessment(): void
    {
        DB::table('orders')->where('id', $this->orderId)
            ->update(['payment_status' => 'paid']);

        $final = app(CheckoutPaymentFinalizationService::class)
            ->finalize($this->receipt());
        $case = $this->cases()->findForDecision($final['decision_id']);

        $this->assertSame('already_paid', $final['reason_code']);
        $this->assertSame('paid', $final['order_payment_status']);
        $this->assertSame('possible_extra_capture', $case['case_type']);
        $this->assertSame('assess_refund', $case['suggested_action']);
    }

    public function test_capture_for_canceled_order_is_flagged_without_reopening_order(): void
    {
        DB::table('orders')->where('id', $this->orderId)
            ->update(['payment_status' => 'canceled']);

        $final = app(CheckoutPaymentFinalizationService::class)
            ->finalize($this->receipt());
        $case = $this->cases()->findForDecision($final['decision_id']);

        $this->assertSame('order_canceled', $final['reason_code']);
        $this->assertSame('canceled', $final['order_payment_status']);
        $this->assertSame('possible_extra_capture', $case['case_type']);
        $this->assertSame('assess_refund', $case['suggested_action']);
    }

    public function test_amount_mismatch_preserves_expected_and_captured_amounts(): void
    {
        $this->financialSnapshot(2000);
        $decisionId = $this->decision('amount_mismatch');

        $case = $this->cases()->ensureForDecision($decisionId);

        $this->assertSame('payment_mismatch', $case['case_type']);
        $this->assertSame('reconcile_amount', $case['suggested_action']);
        $this->assertSame(2500, $case['captured_amount_minor']);
        $this->assertSame(2000, $case['expected_amount_minor']);
        $this->assertSame('UAH', $case['captured_currency_code']);
        $this->assertSame('UAH', $case['expected_currency_code']);
    }

    public function test_bonus_conflict_is_classified_as_fulfillment_reconciliation(): void
    {
        $case = $this->cases()->ensureForDecision($this->decision('bonus_mismatch'));
        $this->assertSame('reservation_conflict', $case['case_type']);
        $this->assertSame('reconcile_fulfillment', $case['suggested_action']);
    }

    public function test_unknown_review_reason_is_not_assumed_refundable(): void
    {
        $case = $this->cases()->ensureForDecision($this->decision('new_review_reason'));
        $this->assertSame('other', $case['case_type']);
        $this->assertSame('investigate', $case['suggested_action']);
    }

    public function test_paid_decision_cannot_open_review_case(): void
    {
        $decisionId = $this->decision('accepted', 'paid');
        $this->expectException(DomainException::class);
        $this->cases()->ensureForDecision($decisionId);
    }

    public function test_missing_decision_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->cases()->ensureForDecision(99999999);
    }

    public function test_receipt_and_decision_from_different_orders_are_rejected(): void
    {
        $anotherOrder = (int) DB::table('orders')->insertGetId([
            'order_key' => (string) Str::uuid(),
            'payment_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $decisionId = $this->decision('amount_mismatch', 'review', $anotherOrder);

        $this->expectException(DomainException::class);
        $this->cases()->ensureForDecision($decisionId);
    }

    public function test_two_reviewed_captures_for_same_order_have_separate_cases(): void
    {
        $first = $this->cases()->ensureForDecision($this->decision('amount_mismatch'));
        $second = $this->cases()->ensureForDecision($this->decision('already_paid'));

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($this->orderId, $first['order_id']);
        $this->assertSame($this->orderId, $second['order_id']);
        $this->assertDatabaseCount('checkout_payment_review_cases', 2);
    }

    public function test_database_blocks_updating_review_case(): void
    {
        $case = $this->cases()->ensureForDecision($this->decision('amount_mismatch'));
        $this->expectException(QueryException::class);
        DB::table('checkout_payment_review_cases')
            ->where('id', $case['id'])
            ->update(['suggested_action' => 'assess_refund']);
    }

    public function test_database_blocks_deleting_review_case(): void
    {
        $case = $this->cases()->ensureForDecision($this->decision('amount_mismatch'));
        $this->expectException(QueryException::class);
        DB::table('checkout_payment_review_cases')->where('id', $case['id'])->delete();
    }

    private function cases(): CheckoutPaymentReviewCaseService
    {
        return app(CheckoutPaymentReviewCaseService::class);
    }

    private function receipt(): int
    {
        $recorded = app(CheckoutPaymentCaptureReceiptService::class)
            ->recordVerifiedCapture(
                'test_gateway',
                'evt-' . Str::uuid(),
                'cap-' . Str::uuid(),
                $this->orderKey,
                2500,
                'UAH',
                CarbonImmutable::now('UTC')
            );

        return $recorded['id'];
    }

    private function decision(
        string $reason,
        string $type = 'review',
        ?int $overrideOrderId = null
    ): int {
        return (int) DB::table('checkout_payment_capture_decisions')->insertGetId([
            'receipt_id' => $this->receipt(),
            'order_id' => $overrideOrderId ?? $this->orderId,
            'decision' => $type,
            'reason_code' => $reason,
        ]);
    }

    private function financialSnapshot(int $payable): void
    {
        $snapshot = [
            'schema_version' => 1,
            'currency_code' => 'UAH',
            'goods_payable_minor' => $payable,
        ];
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        DB::table('order_financial_snapshots')->insert([
            'order_id' => $this->orderId,
            'schema_version' => 1,
            'currency_code' => 'UAH',
            'goods_payable_minor' => $payable,
            'snapshot_json' => $json,
            'snapshot_sha256' => hash('sha256', $json),
        ]);
    }
}
