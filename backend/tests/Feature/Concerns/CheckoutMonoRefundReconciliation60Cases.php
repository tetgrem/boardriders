<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Services\Pricing\CheckoutMonoRefund54Service;
use App\Services\Pricing\CheckoutMonoRefundReconciliation60Service;
use App\Services\Pricing\CheckoutMonoRmaRefund53Service;
use App\Services\Pricing\CheckoutOrderReturnService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Uses CheckoutMonoRefund54Test fixtures: paidOrder(), approvedPlan(),
 * submit(), invoice(), replaceMonoHttpFake(), actor(), and journal58().
 */
trait CheckoutMonoRefundReconciliation60Cases
{
    private function capture60(int $orderId): int
    {
        return (int) DB::table('checkout_payment_capture_receipts')
            ->where('order_id', $orderId)
            ->where('provider_code', 'mono')
            ->value('id');
    }

    private function fixtureCapture60(string $orderKey): int
    {
        $id = (int) DB::table('orders')
            ->where('order_key', $orderKey)->value('id');
        return $this->capture60($id);
    }

    /**
     * Open the second unit of an already-paid two-unit order, inspect and
     * approve a separate RMA, then obtain another partial refund plan.
     */
    private function secondPartialPlan60(string $orderKey): array
    {
        $this->actor(5305);
        $return = app(CheckoutOrderReturnService::class)->open(
            $orderKey,
            (string) Str::uuid(),
            'customer_return',
            [['variant_id' => $this->variantId, 'quantity' => 1]]
        );

        $this->actor(5306);
        app(CheckoutOrderReturnService::class)->inspect(
            $return['return_key'],
            (string) Str::uuid(),
            $this->warehouseId,
            'MONO60-SECOND-RECEIPT',
            [[
                'variant_id' => $this->variantId,
                'unit_index' => (int) $return['units'][0]['unit_index'],
                'condition' => 'resellable',
            ]]
        );

        $this->actor(5307);
        app(CheckoutOrderReturnService::class)->decide(
            $return['return_key'],
            (string) Str::uuid(),
            'approved'
        );

        $this->actor(5304);
        return app(CheckoutMonoRmaRefund53Service::class)->plan(
            $return['return_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_mono60_empty_paid_capture_has_full_available_budget(): void
    {
        $paid = $this->paidOrder();
        $receiptId = $this->fixtureCapture60($paid['order_key']);
        $this->actor(6001);

        $result = app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($receiptId);

        $this->assertSame('no_refunds', $result['result_code']);
        $this->assertSame(2000, $result['captured_minor']);
        $this->assertSame(0, $result['planned_minor']);
        $this->assertSame(2000, $result['available_to_plan_minor']);
        $this->assertSame([], $result['lines']);
        $this->assertFalse($result['card_settlement_confirmed']);
    }

    public function test_cod_mono60_unexecuted_plan_reserves_part_of_capture(): void
    {
        $fixture = $this->approvedPlan();
        $this->actor(6001);
        $result = app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($this->fixtureCapture60($fixture['order_key']));

        $this->assertSame('awaiting_execution', $result['result_code']);
        $this->assertSame(1000, $result['planned_minor']);
        $this->assertSame(0, $result['executed_minor']);
        $this->assertSame(1000, $result['available_to_plan_minor']);
        $this->assertSame('not_started', $result['lines'][0]['line_status']);
    }

    public function test_cod_mono60_two_partial_returns_use_single_capture_budget(): void
    {
        $first = $this->approvedPlan();
        $second = $this->secondPartialPlan60($first['order_key']);
        $this->assertSame(1000, (int) $second['amount_minor']);

        $this->actor(6001);
        $result = app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($this->fixtureCapture60($first['order_key']));

        $this->assertSame('awaiting_execution', $result['result_code']);
        $this->assertSame(2, $result['plan_count']);
        $this->assertSame(2000, $result['planned_minor']);
        $this->assertSame(0, $result['available_to_plan_minor']);
        $this->assertCount(2, $result['lines']);
    }

    public function test_cod_mono60_pending_provider_result_keeps_full_reservation(): void
    {
        $fixture = $this->approvedPlan();
        $this->submit($fixture);
        $this->actor(6001);
        $result = app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($this->fixtureCapture60($fixture['order_key']));

        $this->assertSame('awaiting_provider_evidence', $result['result_code']);
        $this->assertSame(1000, $result['executed_minor']);
        $this->assertSame(0, $result['confirmed_minor']);
        $this->assertSame(1, $result['unresolved_count']);
        $this->assertSame(1000, $result['available_to_plan_minor']);
    }

    public function test_cod_mono60_confirmed_and_journaled_is_provider_reconciled(): void
    {
        $fixture = $this->journal58();
        $this->actor(6001);
        $service = app(CheckoutMonoRefundReconciliation60Service::class);
        $result = $service->reconcile($this->capture60($fixture['order_id']));

        $this->assertSame('provider_reconciled', $result['result_code']);
        $this->assertSame(1000, $result['confirmed_minor']);
        $this->assertSame(1000, $result['journaled_minor']);
        $this->assertSame(0, $result['unresolved_count']);
        $this->assertSame(0, $result['unjournaled_count']);
        $this->assertTrue($result['provider_refunds_reconciled']);
        $this->assertFalse($result['card_settlement_confirmed']);
        $this->actor(6002);
        $this->assertSame($result, $service->show($result['reconciliation_id']));
    }

    public function test_cod_mono60_same_evidence_is_idempotent(): void
    {
        $fixture = $this->approvedPlan();
        $receiptId = $this->fixtureCapture60($fixture['order_key']);
        $this->actor(6001);
        $service = app(CheckoutMonoRefundReconciliation60Service::class);

        $first = $service->reconcile($receiptId);
        $second = $service->reconcile($receiptId);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_mono_refund_reconciliations60', 1);
        $this->assertDatabaseCount('checkout_mono_refund_reconciliation_lines60', 1);
    }

    public function test_cod_mono60_new_execution_creates_new_immutable_snapshot(): void
    {
        $fixture = $this->approvedPlan();
        $receiptId = $this->fixtureCapture60($fixture['order_key']);
        $this->actor(6001);
        $service = app(CheckoutMonoRefundReconciliation60Service::class);
        $first = $service->reconcile($receiptId);

        $this->submit($fixture);
        $this->actor(6001);
        $second = $service->reconcile($receiptId);

        $this->assertNotSame($first['reconciliation_id'], $second['reconciliation_id']);
        $this->assertSame('awaiting_execution', $first['result_code']);
        $this->assertSame('awaiting_provider_evidence', $second['result_code']);
        $this->assertDatabaseCount('checkout_mono_refund_reconciliations60', 2);
        $this->actor(6002);
        $this->assertSame($first, $service->show($first['reconciliation_id']));
    }

    public function test_cod_mono60_invalid_provider_observation_needs_manual_review(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];
        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response([], 200),
        ]);
        $this->actor(5402);
        app(CheckoutMonoRefund54Service::class)->reconcile($sent['execution_id']);

        $this->actor(6001);
        $result = app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($this->fixtureCapture60($fixture['order_key']));
        $this->assertSame('needs_review', $result['result_code']);
        $this->assertSame(1, $result['unresolved_count']);
        $this->assertSame(0, $result['confirmed_minor']);
    }

    public function test_cod_mono60_denies_user_without_finance_ability(): void
    {
        $fixture = $this->approvedPlan();
        $this->actor(5305);
        $this->expectException(AuthorizationException::class);
        app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($this->fixtureCapture60($fixture['order_key']));
    }

    public function test_cod_mono60_reconciliation_rows_are_immutable(): void
    {
        $fixture = $this->approvedPlan();
        $this->actor(6001);
        $result = app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($this->fixtureCapture60($fixture['order_key']));
        $this->expectException(QueryException::class);
        DB::table('checkout_mono_refund_reconciliations60')
            ->where('id', $result['reconciliation_id'])
            ->update(['result_code' => 'provider_reconciled']);
    }

    public function test_cod_mono60_reconciliation_never_sends_mono_requests(): void
    {
        $fixture = $this->approvedPlan();
        $this->replaceMonoHttpFake([]);
        $this->actor(6001);
        app(CheckoutMonoRefundReconciliation60Service::class)
            ->reconcile($this->fixtureCapture60($fixture['order_key']));
        Http::assertNothingSent();
    }

    public function test_cod_mono60_api_routes_are_protected_by_sanctum(): void
    {
        foreach (['mono60.reconcile', 'mono60.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
