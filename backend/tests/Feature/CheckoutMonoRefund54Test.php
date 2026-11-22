<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use App\Services\Pricing\CheckoutMonoPayment53Service;
use App\Services\Pricing\CheckoutMonoRefund54Service;
use App\Services\Pricing\CheckoutMonoRmaRefund53Service;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutOrderReturnService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class CheckoutMonoRefund54Test extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\CheckoutMonoRefund56Cases;
    use \Tests\Feature\Concerns\CheckoutMonoRefund57Cases;
    use \Tests\Feature\Concerns\CheckoutRmaInventory58Cases;
    use \Tests\Feature\Concerns\CheckoutRmaClosure59Cases;
    use \Tests\Feature\Concerns\CheckoutMonoRefundReconciliation60Cases;
    use \Tests\Feature\Concerns\Fiscal61Cases;
    use \Tests\Feature\Concerns\Fiscal62Cases;


    private int $variantId;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

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

        $listId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL',
            'name' => 'UA retail',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = (int) DB::table(
            'product_variants'
        )->insertGetId([
            'product_id' => $productId,
            'sku' => 'MONO54-' . Str::uuid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $listId,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->warehouseId = (int) DB::table(
            'warehouses'
        )->insertGetId([
            'code' => 'MONO54-' . Str::uuid(),
            'name' => 'Mono 54 warehouse',
            'country_code' => 'UA',
            'kind' => 'owned',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $this->warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 20,
            'reserved_qty' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        config()->set(
            'services.mono_acquiring.enabled',
            true
        );
        config()->set('fiscal62.enabled', true);
        config()->set(
            'services.mono_acquiring.rma_close.enabled',
            true
        );
        config()->set('fiscal61.enabled', true);
        config()->set('fiscal61.preview_enabled', true);
        config()->set(
            'services.mono_acquiring.inventory_returns.posting_enabled',
            true
        );

        config()->set(
            'services.mono_acquiring.loyalty_refunds.posting_enabled',
            true
        );
        config()->set(
            'services.mono_acquiring.refund_reconciliation.enabled',
            true
        );
        config()->set(
            'services.mono_acquiring.token',
            'mono-refund-test-token'
        );

        config()->set(
            'services.mono_acquiring.refunds.enabled',
            true
        );

        config()->set(
            'services.mono_acquiring.refunds.non_fiscalized_merchant_confirmed',
            true
        );

        config()->set(
            'services.mono_acquiring.webhook_url',
            'https://shop.example.test/api/v1/payments/mono/webhook'
        );

        config()->set(
            'services.mono_acquiring.redirect_url',
            'https://shop.example.test/checkout/result'
        );

        $abilities = [
            CheckoutMonoPayment53Service::CREATE_ABILITY => 5301,
            CheckoutMonoPayment53Service::RECONCILE_ABILITY => 5302,
            CheckoutMonoRmaRefund53Service::PLAN_ABILITY => 5304,
            CheckoutOrderReturnService::VIEW_ABILITY => 5304,
            CheckoutOrderReturnService::OPEN_ABILITY => 5305,
            CheckoutOrderReturnService::INSPECT_ABILITY => 5306,
            CheckoutOrderReturnService::DECIDE_ABILITY => 5307,
            CheckoutMonoRefund54Service::SEND_ABILITY => 5401,
            CheckoutMonoRefund54Service::RECONCILE_ABILITY => 5402,
            CheckoutMonoRefund54Service::RECOVER_ABILITY => 5403,
            \App\Services\Pricing\CheckoutMonoRefund56JournalService::JOURNAL_ABILITY => 5601,
            \App\Services\Pricing\CheckoutMonoRefund57LoyaltyService::APPROVE_ABILITY => 5701,
            \App\Services\Pricing\CheckoutMonoRefund57LoyaltyService::POST_ABILITY => 5702,
            \App\Services\Pricing\CheckoutRmaInventory58Service::POST_ABILITY => 5801,
            \App\Services\Pricing\CheckoutRmaClosure59Service::CLOSE_ABILITY => 5901,
            \App\Services\Pricing\CheckoutMonoRefundReconciliation60Service::RUN_ABILITY => 6001,
            \App\Services\Pricing\CheckoutMonoRefundReconciliation60Service::VIEW_ABILITY => 6002,
            \App\Services\Fiscal61\Fiscal61Authorization::PREPARE => 6101,
            \App\Services\Fiscal61\Fiscal61Authorization::SIMULATE => 6102,
            \App\Services\Fiscal61\Fiscal61Authorization::VIEW => 6103,
            \App\Services\Fiscal61\Fiscal61Authorization::INCIDENT => 6104,
            \App\Services\Fiscal62\Fiscal62Access::PROFILE => 6201,
            \App\Services\Fiscal62\Fiscal62Access::PREPARE => 6202,
            \App\Services\Fiscal62\Fiscal62Access::SEND => 6203,
            \App\Services\Fiscal62\Fiscal62Access::POLL => 6204,
            \App\Services\Fiscal62\Fiscal62Access::VIEW => 6205,
        ];

        foreach ($abilities as $ability => $userId) {
            Gate::define(
                $ability,
                static fn ($user): bool =>
                    (int) $user->getAuthIdentifier() === $userId
            );
        }

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function actor(int $id): void
    {
        Auth::setUser(
            new GenericUser(['id' => $id])
        );
    }

    /** @return array<string,mixed> */
    private function paidOrder(): array
    {
        $order = app(
            CheckoutOrderPreparationService::class
        )->prepare(
            (string) Str::uuid(),
            [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => 2,
                ],
            ],
            'UA',
            'UA_RETAIL',
            CarbonImmutable::now('UTC')->addMinutes(30)
        );

        app(CheckoutPaymentChoiceService::class)->choose(
            $order['order_key'],
            'card',
            'mono'
        );

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' =>
                Http::response([
                    'invoiceId' => 'p2_mono54',
                    'pageUrl' =>
                        'https://pay.mbnk.biz/p2_mono54',
                ]),
        ]);

        $this->actor(5301);

        app(CheckoutMonoPayment53Service::class)->start(
            $order['order_key'],
            (string) Str::uuid()
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addSeconds(3)
        );

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice($order['order_key'])
                ),
        ]);

        $this->actor(5302);

        $out = app(
            CheckoutMonoPayment53Service::class
        )->reconcile('p2_mono54');

        $this->assertSame('paid', $out['decision']);

        return $order;
    }

    /** @return array<string,mixed> */
    private function invoice(
        string $key,
        array $cancels = []
    ): array {
        return [
            'invoiceId' => 'p2_mono54',
            'reference' => $key,
            'amount' => 2000,
            'finalAmount' => 2000,
            'ccy' => 980,
            'status' => 'success',
            'cancelList' => $cancels,
            'modifiedDate' =>
                CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /** @return array{plan_id:int,order_key:string} */
    private function approvedPlan(): array
    {
        $order = $this->paidOrder();

        $this->actor(5305);

        $return = app(
            CheckoutOrderReturnService::class
        )->open(
            $order['order_key'],
            (string) Str::uuid(),
            'customer_return',
            [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => 1,
                ],
            ]
        );

        $this->actor(5306);

        app(CheckoutOrderReturnService::class)->inspect(
            $return['return_key'],
            (string) Str::uuid(),
            $this->warehouseId,
            'MONO54-RECEIPT',
            [
                [
                    'variant_id' => $this->variantId,
                    'unit_index' =>
                        $return['units'][0]['unit_index'],
                    'condition' => 'resellable',
                ],
            ]
        );

        $this->actor(5307);

        app(CheckoutOrderReturnService::class)->decide(
            $return['return_key'],
            (string) Str::uuid(),
            'approved'
        );

        $this->actor(5304);

        $plan = app(
            CheckoutMonoRmaRefund53Service::class
        )->plan(
            $return['return_key'],
            (string) Str::uuid()
        );

        $this->assertSame(
            1000,
            $plan['amount_minor']
        );

        return [
            'plan_id' => $plan['plan_id'],
            'order_key' => $order['order_key'],
        ];
    }

    /**
     * @return array{result:array<string,mixed>,calls:int}
     */
    private function submit(
        array $fixture,
        string $reply = 'processing'
    ): array {
        $calls = 0;

        Http::fake(function ($request) use (
            &$calls,
            $fixture,
            $reply
        ) {
            if (str_contains(
                $request->url(),
                '/invoice/status'
            )) {
                return Http::response(
                    $this->invoice($fixture['order_key'])
                );
            }

            if (str_contains(
                $request->url(),
                '/invoice/cancel'
            )) {
                $calls++;

                return Http::response([
                    'status' => $reply,
                ]);
            }

            return Http::response([], 500);
        });

        $this->actor(5401);

        $service = app(
            CheckoutMonoRefund54Service::class
        );

        $result = $service->send(
            $fixture['plan_id']
        );

        return [
            'result' => $result,
            'calls' => $calls,
        ];
    }

    // ============================================================
    // BLOCK 54 — SUBMISSION AND IDEMPOTENCY
    // ============================================================
    private function replaceMonoHttpFake(
        array|\Closure $responses
    ): void {
        Http::swap(new \Illuminate\Http\Client\Factory());

        Http::preventStrayRequests();

        Http::fake($responses);
    }
    public function test_cod_mono54_post_submits_once_without_marking_refund_paid(): void
    {
        $fixture = $this->approvedPlan();
        $send = $this->submit($fixture);

        $this->assertSame(
            'awaiting_evidence',
            $send['result']['status']
        );

        $this->assertFalse(
            $send['result']['provider_refund_confirmed']
        );

        $this->assertSame(1, $send['calls']);

        $this->assertSame(
            1,
            $send['result']['observation_count']
        );

        $this->assertDatabaseCount(
            'checkout_mono_refund_executions',
            1
        );
    }

    public function test_cod_mono54_replay_does_not_reissue_money_post(): void
    {
        $fixture = $this->approvedPlan();

        $send = $this->submit($fixture);

        $repeatCalls = 0;

        Http::fake(function () use (&$repeatCalls) {
            $repeatCalls++;

            return Http::response([], 500);
        });

        $replay = app(
            CheckoutMonoRefund54Service::class
        )->send($fixture['plan_id']);

        $this->assertSame(
            $send['result']['execution_id'],
            $replay['execution_id']
        );

        $this->assertSame(
            'awaiting_evidence',
            $replay['status']
        );

        $this->assertSame(0, $repeatCalls);
    }

    // ============================================================
    // BLOCK 54 — PROVIDER RECONCILIATION
    // ============================================================

    public function test_cod_mono54_confirmed_cancel_list_succeeds_only_after_get(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $c = [[
            'extRef' => $sent['ext_ref'],
            'status' => 'success',
            'amount' => 1000,
            'ccy' => 980,
        ]];

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice($fixture['order_key'], $c)
                ),
        ]);

        $this->actor(5402);

        $out = app(
            CheckoutMonoRefund54Service::class
        )->reconcile(
            $sent['execution_id']
        );

        $this->assertSame(
            'succeeded',
            $out['status']
        );

        $this->assertTrue(
            $out['provider_refund_confirmed']
        );

        $this->assertFalse(
            $out['card_settlement_confirmed']
        );

        $this->assertSame(
            2,
            $out['observation_count']
        );

        $this->assertDatabaseHas(
            'checkout_mono_refund_observations',
            [
                'execution_id' => $sent['execution_id'],
                'state' => 'success',
                'source' => 'invoice_status',
                'matched_amount_minor' => 1000,
            ]
        );
    }

    public function test_cod_mono54_processing_cancel_list_is_not_success(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice(
                        $fixture['order_key'],
                        [[
                            'extRef' => $sent['ext_ref'],
                            'status' => 'processing',
                            'amount' => 1000,
                            'ccy' => 980,
                        ]]
                    )
                ),
        ]);

        $this->actor(5402);

        $out = app(
            CheckoutMonoRefund54Service::class
        )->reconcile(
            $sent['execution_id']
        );

        $this->assertSame(
            'awaiting_evidence',
            $out['status']
        );

        $this->assertFalse(
            $out['provider_refund_confirmed']
        );
    }

    public function test_cod_mono54_unknown_external_refund_blocks_money_post(): void
    {
        $fixture = $this->approvedPlan();

        $cancels = [[
            'extRef' => 'other-merchant-ref',
            'status' => 'success',
            'amount' => 200,
            'ccy' => 980,
        ]];

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice(
                        $fixture['order_key'],
                        $cancels
                    )
                ),
        ]);

        $this->actor(5401);

        $out = app(
            CheckoutMonoRefund54Service::class
        )->send(
            $fixture['plan_id']
        );

        $this->assertSame(
            'needs_review',
            $out['status']
        );

        $this->assertSame(
            'blocked',
            $out['last_evidence_state']
        );

        Http::assertNotSent(
            fn ($r): bool => str_contains(
                $r->url(),
                '/invoice/cancel'
            )
        );
    }

    public function test_cod_mono54_uncertain_cancel_post_never_retries(): void
    {
        $fixture = $this->approvedPlan();
        $calls = 0;

        $this->replaceMonoHttpFake(function ($request) use (
            $fixture,
            &$calls
        ) {
            if (str_contains(
                $request->url(),
                '/invoice/status'
            )) {
                return Http::response(
                    $this->invoice($fixture['order_key'])
                );
            }

            $calls++;

            return Http::response([
                'message' => 'temporarily unavailable',
            ], 500);
        });

        $this->actor(5401);

        $svc = app(
            CheckoutMonoRefund54Service::class
        );

        $first = $svc->send($fixture['plan_id']);
        $second = $svc->send($fixture['plan_id']);

        $this->assertSame(
            'awaiting_evidence',
            $first['status']
        );

        $this->assertSame(
            $first['execution_id'],
            $second['execution_id']
        );

        $this->assertSame(1, $calls);
    }

    public function test_cod_mono54_provider_get_failure_does_not_send_refund(): void
    {
        $fixture = $this->approvedPlan();

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response([], 503),
        ]);

        $this->actor(5401);

        $out = app(
            CheckoutMonoRefund54Service::class
        )->send(
            $fixture['plan_id']
        );

        $this->assertSame(
            'awaiting_evidence',
            $out['status']
        );

        Http::assertNotSent(
            fn ($r): bool => str_contains(
                $r->url(),
                '/invoice/cancel'
            )
        );
    }

    public function test_cod_mono54_cancel_list_wrong_amount_never_confirms_refund(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice(
                        $fixture['order_key'],
                        [[
                            'extRef' => $sent['ext_ref'],
                            'status' => 'success',
                            'amount' => 999,
                            'ccy' => 980,
                        ]]
                    )
                ),
        ]);

        $this->actor(5402);

        $out = app(
            CheckoutMonoRefund54Service::class
        )->reconcile(
            $sent['execution_id']
        );

        $this->assertSame(
            'needs_review',
            $out['status']
        );

        $this->assertFalse(
            $out['provider_refund_confirmed']
        );
    }

    // ============================================================
    // BLOCK 54 — PERMISSIONS AND SAFETY FLAGS
    // ============================================================

    public function test_cod_mono54_planner_cannot_execute_refund(): void
    {
        $fixture = $this->approvedPlan();

        $this->actor(5304);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutMonoRefund54Service::class)->send(
            $fixture['plan_id']
        );
    }

    public function test_cod_mono54_feature_flag_fails_closed(): void
    {
        $fixture = $this->approvedPlan();

        $this->actor(5401);

        config()->set(
            'services.mono_acquiring.refunds.enabled',
            false
        );

        $this->expectException(
            DomainException::class
        );

        app(CheckoutMonoRefund54Service::class)->send(
            $fixture['plan_id']
        );
    }

    // ============================================================
    // BLOCK 54 — DATABASE PROTECTION
    // ============================================================

    public function test_cod_mono54_db_cannot_set_success_without_provider_evidence(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $this->expectException(
            QueryException::class
        );

        DB::table('checkout_mono_refund_executions')
            ->where('id', $sent['execution_id'])
            ->update([
                'status' => 'succeeded',
            ]);
    }

    public function test_cod_mono54_provider_observation_is_immutable(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $this->expectException(
            QueryException::class
        );

        DB::table('checkout_mono_refund_observations')
            ->where(
                'execution_id',
                $sent['execution_id']
            )
            ->delete();
    }

    public function test_cod_mono54_http_routes_require_sanctum(): void
    {
        foreach ([
                     'mono54.send',
                     'mono54.reconcile',
                     'mono54.show',
                 ] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()
                ->getByName($name);

            $this->assertNotNull($route);

            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware()
            );
        }
    }
    // ============================================================
// BOARDRIDERS #55 — STALE MONO REFUND RECOVERY
// ============================================================

    /** @return array{id:int,ext_ref:string} */
    private function stuckExecution55(array $fixture): array
    {
        $plan = DB::table('checkout_mono_rma_refund_plans')
            ->where('id', $fixture['plan_id'])
            ->first();

        $receipt = DB::table(
            'checkout_payment_capture_receipts'
        )
            ->where('id', $plan->capture_receipt_id)
            ->first();

        $identity = [
            'refund_plan_id' => (int) $plan->id,
            'order_id' => (int) $plan->order_id,
            'capture_receipt_id' =>
                (int) $plan->capture_receipt_id,
            'operation_key' => (string) $plan->operation_key,
            'ext_ref' => str_replace(
                '-',
                '',
                (string) $plan->operation_key
            ),
            'invoice_id' =>
                (string) $receipt->provider_payment_id,
            'amount_minor' => (int) $plan->amount_minor,
            'currency_code' => 'UAH',
            'executed_by' => 5401,
        ];

        // Симулируем обрыв PHP-процесса после записи заявки
        // в БД, но до получения ответа от Mono.
        $id = (int) DB::table(
            'checkout_mono_refund_executions'
        )->insertGetId([
            ...$identity,
            'status' => 'sending',
            'request_hmac' =>
                app(CheckoutOrderReturnSigner::class)
                    ->digest($identity),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'ext_ref' => $identity['ext_ref'],
        ];
    }

    private function advance55(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addMinutes(16)
        );
    }

    public function test_cod_mono55_fresh_sending_cannot_be_recovered(): void
    {
        $fixture = $this->approvedPlan();
        $stuck = $this->stuckExecution55($fixture);

        $this->actor(5403);

        $this->expectException(DomainException::class);

        app(CheckoutMonoRefund54Service::class)
            ->recoverStale($stuck['id']);
    }

    public function test_cod_mono55_stale_absent_is_review_not_retry(): void
    {
        $fixture = $this->approvedPlan();
        $stuck = $this->stuckExecution55($fixture);

        $this->advance55();

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice($fixture['order_key'])
                ),
        ]);

        $this->actor(5403);

        $service = app(
            CheckoutMonoRefund54Service::class
        );

        $out = $service->recoverStale($stuck['id']);

        $this->assertSame(
            'needs_review',
            $out['status']
        );

        $this->assertSame(
            'absent',
            $out['last_evidence_state']
        );

        $this->assertFalse(
            $out['provider_refund_confirmed']
        );

        $this->assertDatabaseHas(
            'checkout_mono_refund_observations',
            [
                'execution_id' => $stuck['id'],
                'state' => 'absent',
                'reviewed_by' => 5403,
            ]
        );

        Http::assertNotSent(
            fn ($r): bool => str_contains(
                $r->url(),
                '/invoice/cancel'
            )
        );

        $this->actor(5401);

        $replayed = $service->send(
            $fixture['plan_id']
        );

        $this->assertSame(
            $stuck['id'],
            $replayed['execution_id']
        );

        $this->assertSame(
            'needs_review',
            $replayed['status']
        );

        Http::assertNotSent(
            fn ($r): bool => str_contains(
                $r->url(),
                '/invoice/cancel'
            )
        );
    }

    public function test_cod_mono55_stale_provider_success_is_verified(): void
    {
        $fixture = $this->approvedPlan();
        $stuck = $this->stuckExecution55($fixture);

        $this->advance55();

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice(
                        $fixture['order_key'],
                        [[
                            'extRef' => $stuck['ext_ref'],
                            'status' => 'success',
                            'amount' => 1000,
                            'ccy' => 980,
                        ]]
                    )
                ),
        ]);

        $this->actor(5403);

        $out = app(
            CheckoutMonoRefund54Service::class
        )->recoverStale($stuck['id']);

        $this->assertSame(
            'succeeded',
            $out['status']
        );

        $this->assertTrue(
            $out['provider_refund_confirmed']
        );

        $this->assertFalse(
            $out['card_settlement_confirmed']
        );

        $this->assertSame(
            1,
            $out['observation_count']
        );

        $this->assertDatabaseHas(
            'checkout_mono_refund_observations',
            [
                'execution_id' => $stuck['id'],
                'state' => 'success',
                'reviewed_by' => 5403,
                'matched_amount_minor' => 1000,
            ]
        );

        Http::assertNotSent(
            fn ($r): bool => str_contains(
                $r->url(),
                '/invoice/cancel'
            )
        );
    }

    public function test_cod_mono55_provider_unavailable_needs_manual_review(): void
    {
        $fixture = $this->approvedPlan();
        $stuck = $this->stuckExecution55($fixture);

        $this->advance55();

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response([], 503),
        ]);

        $this->actor(5403);

        $out = app(
            CheckoutMonoRefund54Service::class
        )->recoverStale($stuck['id']);

        $this->assertSame(
            'needs_review',
            $out['status']
        );

        $this->assertSame(
            'unexpected',
            $out['last_evidence_state']
        );

        Http::assertNotSent(
            fn ($r): bool => str_contains(
                $r->url(),
                '/invoice/cancel'
            )
        );
    }

    public function test_cod_mono55_recovery_requires_separate_finance_ability(): void
    {
        $fixture = $this->approvedPlan();
        $stuck = $this->stuckExecution55($fixture);

        $this->advance55();

        $this->actor(5402);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutMonoRefund54Service::class)
            ->recoverStale($stuck['id']);
    }

    public function test_cod_mono55_stale_list_only_contains_old_sending(): void
    {
        $fixture = $this->approvedPlan();
        $stuck = $this->stuckExecution55($fixture);

        $this->actor(5403);

        $service = app(
            CheckoutMonoRefund54Service::class
        );

        $this->assertSame(
            [],
            $service->staleExecutions()
        );

        $this->advance55();

        $list = $service->staleExecutions();

        $this->assertCount(1, $list);

        $this->assertSame(
            $stuck['id'],
            $list[0]['execution_id']
        );
    }

    public function test_cod_mono55_database_budget_guard_is_installed(): void
    {
        $row = DB::selectOne(<<<'SQL'
        SELECT COUNT(*) AS total
        FROM pg_trigger
        WHERE tgname = 'mono55_refund_budget_guard'
          AND NOT tgisinternal
    SQL);

        $this->assertSame(
            1,
            (int) $row->total
        );
    }

    public function test_cod_mono55_routes_use_sanctum(): void
    {
        foreach ([
                     'mono55.stale',
                     'mono55.recover',
                 ] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()
                ->getByName($name);

            $this->assertNotNull($route);

            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware()
            );
        }
    }
}
