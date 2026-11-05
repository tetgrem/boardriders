<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Pricing\CheckoutPaymentCaptureReceiptService;
use App\Services\Pricing\CheckoutPaymentReviewCaseService;
use App\Services\Pricing\CheckoutPaymentReviewResolutionService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** PostgreSQL boardriders_test only. No actual provider refunds. */
final class CheckoutPaymentReviewResolutionTest extends TestCase
{
    use DatabaseTransactions;

    private User $reviewer;
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

        $this->reviewer = User::factory()->create();
        $this->orderKey = (string) Str::uuid();
        $this->orderId = (int) DB::table('orders')->insertGetId([
            'order_key' => $this->orderKey,
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_full_refund_proposal_creates_immutable_intent_without_refunding(): void
    {
        $case = $this->reviewCase();
        $resolved = $this->resolve($case['id'], amount: 2500);

        $this->assertSame('refund_proposed', $resolved['resolution']);
        $this->assertSame(2500, $resolved['refund_amount_minor']);
        $this->assertSame(2500, $resolved['refund_intent']['amount_minor']);
        $this->assertSame('UAH', $resolved['refund_intent']['currency_code']);
        $this->assertSame('test_gateway', $resolved['refund_intent']['provider_code']);
        $this->assertTrue(Str::isUuid($resolved['refund_intent']['refund_key']));
        $this->assertSame($case['receipt_id'], $resolved['receipt_id']);
        $this->assertSame('paid', DB::table('orders')
            ->where('id', $this->orderId)->value('payment_status'));
        $this->assertDatabaseCount('checkout_payment_review_resolutions', 1);
        $this->assertDatabaseCount('checkout_payment_refund_intents', 1);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_partial_refund_proposal_cannot_exceed_capture(): void
    {
        $case = $this->reviewCase();
        $resolved = $this->resolve($case['id'], amount: 700);

        $this->assertSame(700, $resolved['refund_amount_minor']);
        $this->assertSame(700, $resolved['refund_intent']['amount_minor']);
        $this->assertSame(2500, $case['captured_amount_minor']);
    }

    public function test_identical_resolution_retry_is_idempotent(): void
    {
        $case = $this->reviewCase();
        $key = (string) Str::uuid();

        $first = $this->resolve($case['id'], $key, amount: 2500);
        $second = $this->resolve($case['id'], $key, amount: 2500);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_payment_review_resolutions', 1);
        $this->assertDatabaseCount('checkout_payment_refund_intents', 1);
    }

    public function test_reused_key_with_different_refund_amount_is_rejected(): void
    {
        $case = $this->reviewCase();
        $key = (string) Str::uuid();
        $this->resolve($case['id'], $key, amount: 2500);

        $this->expectException(DomainException::class);
        $this->resolve($case['id'], $key, amount: 1000);
    }

    public function test_second_resolution_operation_for_same_case_is_rejected(): void
    {
        $case = $this->reviewCase();
        $this->resolve($case['id'], amount: 2500);

        $this->expectException(DomainException::class);
        $this->resolve($case['id'], amount: 2500);
    }

    public function test_same_operation_key_cannot_resolve_two_cases(): void
    {
        $firstCase = $this->reviewCase();
        $secondCase = $this->reviewCase('amount_mismatch');
        $key = (string) Str::uuid();

        $this->resolve($firstCase['id'], $key, amount: 2500);

        $this->expectException(DomainException::class);
        $this->resolve($secondCase['id'], $key, amount: 2500);
    }

    public function test_refund_more_than_captured_is_rejected_without_side_effects(): void
    {
        $case = $this->reviewCase();

        try {
            $this->resolve($case['id'], amount: 2501);
            $this->fail('Over-refund must fail');
        } catch (DomainException) {
            $this->assertDatabaseCount('checkout_payment_review_resolutions', 0);
            $this->assertDatabaseCount('checkout_payment_refund_intents', 0);
        }
    }

    public function test_no_refund_resolution_creates_no_refund_intent(): void
    {
        $case = $this->reviewCase();
        $resolved = $this->resolve(
            $case['id'],
            resolution: 'no_refund',
            amount: null
        );

        $this->assertSame('no_refund', $resolved['resolution']);
        $this->assertNull($resolved['refund_amount_minor']);
        $this->assertNull($resolved['refund_intent']);
        $this->assertSame($resolved, app(CheckoutPaymentReviewResolutionService::class)
            ->findForCase($case['id']));
        $this->assertDatabaseCount('checkout_payment_refund_intents', 0);
    }

    public function test_no_refund_with_monetary_amount_is_rejected(): void
    {
        $case = $this->reviewCase();

        $this->expectException(InvalidArgumentException::class);
        $this->resolve($case['id'], resolution: 'no_refund', amount: 100);
    }

    public function test_paid_decision_cannot_be_resolved_as_review(): void
    {
        $receipt = $this->receipt();
        $decisionId = (int) DB::table('checkout_payment_capture_decisions')
            ->insertGetId([
                'receipt_id' => $receipt['id'],
                'order_id' => $this->orderId,
                'decision' => 'paid',
                'reason_code' => 'accepted',
            ]);

        // Навмисно неузгоджений рядок для перевірки fail-closed захисту.
        $caseId = (int) DB::table('checkout_payment_review_cases')->insertGetId([
            'decision_id' => $decisionId,
            'case_type' => 'other',
            'suggested_action' => 'investigate',
            'captured_amount_minor' => 2500,
            'captured_currency_code' => 'UAH',
        ]);

        $this->expectException(DomainException::class);
        $this->resolve($caseId, amount: 2500);
    }

    public function test_database_blocks_mutating_resolution(): void
    {
        $case = $this->reviewCase();
        $resolved = $this->resolve($case['id'], amount: 2500);

        $this->expectException(QueryException::class);
        DB::table('checkout_payment_review_resolutions')
            ->where('id', $resolved['resolution_id'])
            ->update(['reason_note' => 'A changed reason must never be saved']);
    }

    public function test_database_rejects_forged_intent_for_no_refund_resolution(): void
    {
        $case = $this->reviewCase();
        $resolved = $this->resolve($case['id'], resolution: 'no_refund', amount: null);

        $this->expectException(QueryException::class);
        DB::table('checkout_payment_refund_intents')->insert([
            'resolution_id' => $resolved['resolution_id'],
            'receipt_id' => $resolved['receipt_id'],
            'refund_key' => (string) Str::uuid(),
            'provider_code' => 'test_gateway',
            'provider_capture_id' => 'forged',
            'amount_minor' => 2500,
            'currency_code' => 'UAH',
        ]);
    }

    /** @return array<string,mixed> */
    private function reviewCase(string $reason = 'already_paid'): array
    {
        $receipt = $this->receipt();

        $decisionId = (int) DB::table('checkout_payment_capture_decisions')
            ->insertGetId([
                'receipt_id' => $receipt['id'],
                'order_id' => $this->orderId,
                'decision' => 'review',
                'reason_code' => $reason,
            ]);

        return app(CheckoutPaymentReviewCaseService::class)
            ->ensureForDecision($decisionId);
    }

    /** @return array<string,mixed> */
    private function receipt(): array
    {
        return app(CheckoutPaymentCaptureReceiptService::class)
            ->recordVerifiedCapture(
                'test_gateway',
                'evt-' . Str::uuid(),
                'cap-' . Str::uuid(),
                $this->orderKey,
                2500,
                'UAH',
                CarbonImmutable::now('UTC')
            );
    }

    /** @return array<string,mixed> */
    private function resolve(
        int $caseId,
        ?string $operationKey = null,
        string $resolution = 'refund_proposed',
        ?int $amount = 2500,
    ): array {
        return app(CheckoutPaymentReviewResolutionService::class)->resolve(
            $caseId,
            $operationKey ?? (string) Str::uuid(),
            $this->reviewer->id,
            $resolution,
            'Reviewed verified payment evidence and documented the decision.',
            $amount
        );
    }
}
