<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Pricing\CheckoutMonoPayment53Service;
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

/**
 * Own fixture.
 * No dependency on the refactored COD Feature monolith.
 */
final class CheckoutMonoAcquiring53Test extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName()
            !== 'boardriders_test'
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

        $priceList = (int) DB::table(
            'price_lists'
        )->insertGetId([
            'code' => 'UA_RETAIL',
            'name' => 'Retail',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $product = (int) DB::table(
            'products'
        )->insertGetId([
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = (int) DB::table(
            'product_variants'
        )->insertGetId([
            'product_id' => $product,
            'sku' => 'MONO53-' . Str::uuid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $priceList,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->warehouseId = (int) DB::table(
            'warehouses'
        )->insertGetId([
            'code' => 'MONO53-' . Str::uuid(),
            'name' => 'Mono warehouse',
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
        config()->set(
            'services.mono_acquiring.token',
            'mono-test-token'
        );
        config()->set(
            'services.mono_acquiring.webhook_url',
            'https://shop.example.test/api/v1/payments/mono/webhook'
        );
        config()->set(
            'services.mono_acquiring.redirect_url',
            'https://shop.example.test/checkout/result'
        );

        Gate::define(
            CheckoutMonoPayment53Service::CREATE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 5301
        );

        Gate::define(
            CheckoutMonoPayment53Service::RECONCILE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 5302
        );

        Gate::define(
            CheckoutMonoRmaRefund53Service::PLAN_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 5304
        );

        Gate::define(
            CheckoutOrderReturnService::VIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 5304
        );

        Gate::define(
            CheckoutOrderReturnService::OPEN_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 5305
        );

        Gate::define(
            CheckoutOrderReturnService::INSPECT_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 5306
        );

        Gate::define(
            CheckoutOrderReturnService::DECIDE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 5307
        );

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function actor(int $id): void
    {
        Auth::setUser(new GenericUser(['id' => $id]));
    }

    private function order(): array
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

        return $order;
    }

    private function createFake(
        string $invoiceId = 'p2_mono53'
    ): void {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create'
            => Http::response([
                'invoiceId' => $invoiceId,
                'pageUrl' =>
                    'https://pay.mbnk.biz/' . $invoiceId,
            ]),
        ]);
    }

    private function launched(
        array $order,
        string $invoiceId = 'p2_mono53'
    ): array {
        $this->createFake($invoiceId);
        $this->actor(5301);

        return app(CheckoutMonoPayment53Service::class)->start(
            $order['order_key'],
            (string) Str::uuid()
        );
    }

    private function providerStatus(
        string $invoiceId,
        string $orderKey,
        string $status = 'success',
        int $amount = 2000
    ): void {
        $timestamp = CarbonImmutable::now('UTC')
            ->toIso8601String();

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/status*'
            => Http::response([
                'invoiceId' => $invoiceId,
                'status' => $status,
                'amount' => $amount,
                'finalAmount' => $amount,
                'ccy' => 980,
                'reference' => $orderKey,
                'modifiedDate' => $timestamp,
            ]),
        ]);
    }

    private function paidOrder(): array
    {
        $order = $this->order();

        $this->launched($order);

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addSeconds(3)
        );

        $this->providerStatus(
            'p2_mono53',
            $order['order_key']
        );

        $this->actor(5302);

        $out = app(
            CheckoutMonoPayment53Service::class
        )->reconcile('p2_mono53');

        $this->assertSame('paid', $out['decision']);

        return $order;
    }

    // ============================================================
    // BLOCK 53 — INVOICE CREATION
    // ============================================================

    public function test_cod_mono53_launch_creates_one_bound_payment_attempt(): void
    {
        $order = $this->order();
        $launch = $this->launched($order);

        $this->assertSame('ready', $launch['status']);
        $this->assertSame(2000, $launch['amount_minor']);
        $this->assertSame(
            'https://pay.mbnk.biz/p2_mono53',
            $launch['payment_url']
        );

        $this->assertDatabaseHas('checkout_payment_attempts', [
            'order_id' => $order['order_id'],
            'provider_code' => 'mono',
            'provider_payment_id' => 'p2_mono53',
        ]);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('id', $order['order_id'])
                ->value('payment_status')
        );
    }

    public function test_cod_mono53_launch_replay_never_posts_another_invoice(): void
    {
        $order = $this->order();
        $this->createFake();
        $this->actor(5301);

        $key = (string) Str::uuid();
        $service = app(CheckoutMonoPayment53Service::class);

        $a = $service->start($order['order_key'], $key);
        $b = $service->start($order['order_key'], $key);

        $this->assertSame($a, $b);

        Http::assertSentCount(1);
    }

    public function test_cod_mono53_second_invoice_key_is_rejected(): void
    {
        $order = $this->order();
        $this->launched($order);
        $this->actor(5301);

        $this->expectException(DomainException::class);

        app(CheckoutMonoPayment53Service::class)->start(
            $order['order_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_mono53_invoice_creation_requires_mono_payment_choice(): void
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

        $this->actor(5301);

        $this->expectException(DomainException::class);

        app(CheckoutMonoPayment53Service::class)->start(
            $order['order_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_mono53_unprivileged_actor_cannot_launch(): void
    {
        $order = $this->order();
        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutMonoPayment53Service::class)->start(
            $order['order_key'],
            (string) Str::uuid()
        );
    }

    // ============================================================
    // BLOCK 53 — CAPTURE VERIFICATION
    // ============================================================

    public function test_cod_mono53_processing_status_does_not_capture(): void
    {
        $order = $this->order();
        $this->launched($order);

        $this->providerStatus(
            'p2_mono53',
            $order['order_key'],
            'processing'
        );

        $this->actor(5302);

        $out = app(
            CheckoutMonoPayment53Service::class
        )->reconcile('p2_mono53');

        $this->assertFalse($out['capture_recorded']);

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            0
        );
    }

    public function test_cod_mono53_provider_amount_mismatch_never_marks_paid(): void
    {
        $order = $this->order();
        $this->launched($order);

        $this->providerStatus(
            'p2_mono53',
            $order['order_key'],
            'success',
            1999
        );

        $this->actor(5302);

        $this->expectException(DomainException::class);

        app(CheckoutMonoPayment53Service::class)
            ->reconcile('p2_mono53');
    }

    public function test_cod_mono53_authoritative_success_runs_existing_finalization(): void
    {
        $order = $this->paidOrder();

        $this->assertDatabaseHas('orders', [
            'id' => $order['order_id'],
            'payment_status' => 'paid',
        ]);

        $this->assertDatabaseHas(
            'checkout_payment_capture_decisions',
            [
                'order_id' => $order['order_id'],
                'decision' => 'paid',
            ]
        );

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            1
        );
    }

    public function test_cod_mono53_success_reconciliation_replay_is_idempotent(): void
    {
        $order = $this->paidOrder();

        $this->providerStatus(
            'p2_mono53',
            $order['order_key']
        );

        $this->actor(5302);

        $out = app(
            CheckoutMonoPayment53Service::class
        )->reconcile('p2_mono53');

        $this->assertSame('paid', $out['decision']);

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            1
        );

        $this->assertDatabaseCount(
            'checkout_payment_capture_decisions',
            1
        );
    }

    public function test_cod_mono53_unsigned_webhook_is_rejected_before_status_lookup(): void
    {
        Http::fake();

        $this->postJson(
            '/api/v1/payments/mono/webhook',
            [
                'invoiceId' => 'p2_mono53',
                'status' => 'success',
            ]
        )->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_cod_mono53_route_guards_and_webhook_route(): void
    {
        foreach ([
                     'mono53.start',
                     'mono53.reconcile',
                     'mono53.refund.plan',
                 ] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()
                ->getByName($name);

            $this->assertNotNull($route);

            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware()
            );
        }

        $webhook = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('mono53.webhook');

        $this->assertNotNull($webhook);

        $this->assertNotContains(
            'auth:sanctum',
            $webhook->gatherMiddleware()
        );
    }

    public function test_cod_mono53_signed_webhook_can_confirm_payment(): void
    {
        $order = $this->order();
        $this->launched($order);

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addSeconds(3)
        );

        $this->providerStatus(
            'p2_mono53',
            $order['order_key']
        );

        $private = openssl_pkey_new([
            'ec' => [
                'curve_name' => 'prime256v1',
            ],
        ]);
        $details = openssl_pkey_get_details($private);

        config()->set(
            'services.mono_acquiring.public_key_base64',
            base64_encode($details['key'])
        );

        $raw = json_encode([
            'invoiceId' => 'p2_mono53',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        openssl_sign(
            $raw,
            $signature,
            $private,
            OPENSSL_ALGO_SHA256
        );

        $response = $this->call(
            'POST',
            '/api/v1/payments/mono/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGN' => base64_encode($signature),
            ],
            $raw
        );

        $response->assertOk()
            ->assertJsonPath('decision', 'paid');

        $this->assertDatabaseHas('orders', [
            'id' => $order['order_id'],
            'payment_status' => 'paid',
        ]);
    }

    public function test_cod_mono53_uncertain_invoice_creation_never_retries_post(): void
    {
        $order = $this->order();

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create'
            => Http::response([], 500),
        ]);

        $this->actor(5301);

        $key = (string) Str::uuid();
        $svc = app(CheckoutMonoPayment53Service::class);

        try {
            $svc->start($order['order_key'], $key);
            $this->fail('Expected provider failure');
        } catch (RuntimeException) {
            $this->assertDatabaseHas(
                'checkout_mono_invoice_launches',
                [
                    'order_id' => $order['order_id'],
                    'status' => 'creating',
                ]
            );
        }

        try {
            $svc->start($order['order_key'], $key);

            $this->fail(
                'Repeated provider POST should be blocked'
            );
        } catch (DomainException) {
            Http::assertSentCount(1);
        }
    }

    // ============================================================
    // BLOCK 53 — RMA REFUND PLANS
    // ============================================================

    public function test_cod_mono53_refund_plan_requires_approved_rma(): void
    {
        $order = $this->paidOrder();

        $this->actor(5305);

        $case = app(CheckoutOrderReturnService::class)->open(
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

        $this->actor(5304);

        $this->expectException(DomainException::class);

        app(CheckoutMonoRmaRefund53Service::class)->plan(
            $case['return_key'],
            (string) Str::uuid()
        );
    }

    private function approvedRma(array $order): array
    {
        $this->actor(5305);

        $case = app(CheckoutOrderReturnService::class)->open(
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
            $case['return_key'],
            (string) Str::uuid(),
            $this->warehouseId,
            'MONO53-RECEIPT',
            [
                [
                    'variant_id' => $this->variantId,
                    'unit_index' =>
                        $case['units'][0]['unit_index'],
                    'condition' => 'resellable',
                ],
            ]
        );

        $this->actor(5307);

        app(CheckoutOrderReturnService::class)->decide(
            $case['return_key'],
            (string) Str::uuid(),
            'approved'
        );

        return $case;
    }

    public function test_cod_mono53_refund_plan_from_paid_rma_is_not_payment(): void
    {
        $order = $this->paidOrder();
        $case = $this->approvedRma($order);

        $this->actor(5304);

        $out = app(
            CheckoutMonoRmaRefund53Service::class
        )->plan(
            $case['return_key'],
            (string) Str::uuid()
        );

        $this->assertSame(
            1000,
            $out['amount_minor']
        );

        $this->assertFalse(
            $out['money_refunded']
        );

        $this->assertDatabaseCount(
            'checkout_mono_rma_refund_plans',
            1
        );

        $this->assertSame(
            'paid',
            DB::table('orders')
                ->where('id', $order['order_id'])
                ->value('payment_status')
        );
    }

    public function test_cod_mono53_refund_plan_is_idempotent(): void
    {
        $order = $this->paidOrder();
        $case = $this->approvedRma($order);

        $this->actor(5304);

        $key = (string) Str::uuid();
        $svc = app(CheckoutMonoRmaRefund53Service::class);

        $svc->plan($case['return_key'], $key);

        $replay = $svc->plan(
            $case['return_key'],
            $key
        );

        $this->assertTrue(
            $replay['idempotent_replay']
        );

        $this->assertDatabaseCount(
            'checkout_mono_rma_refund_plans',
            1
        );
    }

    public function test_cod_mono53_database_rejects_refund_plan_mutation(): void
    {
        $order = $this->paidOrder();
        $case = $this->approvedRma($order);

        $this->actor(5304);

        app(CheckoutMonoRmaRefund53Service::class)->plan(
            $case['return_key'],
            (string) Str::uuid()
        );

        $this->expectException(QueryException::class);

        DB::table('checkout_mono_rma_refund_plans')
            ->delete();
    }
}
