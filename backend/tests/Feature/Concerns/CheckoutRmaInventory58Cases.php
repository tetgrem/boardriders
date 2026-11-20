<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutMonoRefund54Service;
use App\Services\Pricing\CheckoutMonoRefund56JournalService;
use App\Services\Pricing\CheckoutMonoRmaRefund53Service;
use App\Services\Pricing\CheckoutOrderReturnService;
use App\Services\Pricing\CheckoutRmaInventory58Service;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Uses the existing paid Mono + RMA fixture
 * from CheckoutMonoRefund54Test.
 */
trait CheckoutRmaInventory58Cases
{
    /**
     * @param list<string> $conditions
     * @return array{
     *   journal_id:int,
     *   order_id:int,
     *   warehouse_id:int,
     *   variant_id:int
     * }
     */
    private function journal58(
        array $conditions = ['resellable'],
        bool $ship = true
    ): array {
        $order = $this->paidOrder();

        $orderId = (int) $order['order_id'];

        if ($ship) {
            $reservationKey = (string) DB::table(
                'inventory_reservations'
            )
                ->where('order_id', $orderId)
                ->value('reservation_key');

            app(InventoryService::class)->shipOrderReservation(
                $reservationKey,
                2,
                (string) Str::uuid()
            );
        }

        $this->actor(5305);

        $returned = app(
            CheckoutOrderReturnService::class
        )->open(
            $order['order_key'],
            (string) Str::uuid(),
            'customer_return',
            [[
                'variant_id' => $this->variantId,
                'quantity' => count($conditions),
            ]]
        );

        $inspection = [];

        foreach ($returned['units'] as $i => $unit) {
            $inspection[] = [
                'variant_id' => $this->variantId,
                'unit_index' => $unit['unit_index'],
                'condition' => $conditions[$i],
            ];
        }

        $this->actor(5306);

        app(CheckoutOrderReturnService::class)->inspect(
            $returned['return_key'],
            (string) Str::uuid(),
            $this->warehouseId,
            'RMA58-RECEIPT',
            $inspection
        );

        $this->actor(5307);

        app(CheckoutOrderReturnService::class)->decide(
            $returned['return_key'],
            (string) Str::uuid(),
            'approved'
        );

        $this->actor(5304);

        $plan = app(
            CheckoutMonoRmaRefund53Service::class
        )->plan(
            $returned['return_key'],
            (string) Str::uuid()
        );

        $fixture = [
            'plan_id' => $plan['plan_id'],
            'order_key' => $order['order_key'],
        ];

        $sent = $this->submit($fixture)['result'];

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response(
                    $this->invoice(
                        $order['order_key'],
                        [[
                            'extRef' => $sent['ext_ref'],
                            'status' => 'success',
                            'amount' =>
                                (int) $plan['amount_minor'],
                            'ccy' => 980,
                        ]]
                    )
                ),
        ]);

        $this->actor(5402);

        $confirmed = app(
            CheckoutMonoRefund54Service::class
        )->reconcile($sent['execution_id']);

        $this->assertSame(
            'succeeded',
            $confirmed['status']
        );

        $this->actor(5601);

        $journal = app(
            CheckoutMonoRefund56JournalService::class
        )->record($sent['execution_id']);

        return [
            'journal_id' => (int) $journal['journal_id'],
            'order_id' => $orderId,
            'warehouse_id' => $this->warehouseId,
            'variant_id' => $this->variantId,
        ];
    }

    private function stock58(array $fixture): int
    {
        return (int) DB::table('inventory_stocks')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('variant_id', $fixture['variant_id'])
            ->value('on_hand_qty');
    }

    public function test_cod_rma58_resellable_unit_restocked_once(): void
    {
        $fixture = $this->journal58();

        $this->assertSame(
            18,
            $this->stock58($fixture)
        );

        $this->actor(5801);

        $service = app(
            CheckoutRmaInventory58Service::class
        );

        $first = $service->post(
            $fixture['journal_id']
        );

        $again = $service->post(
            $fixture['journal_id']
        );

        $this->assertSame($first, $again);

        $this->assertSame(
            19,
            $this->stock58($fixture)
        );

        $this->assertSame(
            1,
            $first['sellable_units']
        );

        $this->assertSame(
            0,
            $first['quarantine_units']
        );

        $this->assertFalse(
            $first['rma_closed']
        );

        $this->assertDatabaseCount(
            'checkout_rma_stock_units58',
            1
        );

        $this->assertDatabaseCount(
            'checkout_rma_stock_batches58',
            1
        );

        $this->assertDatabaseCount(
            'inventory_receipts',
            1
        );
    }

    public function test_cod_rma58_unshipped_goods_cannot_increase_stock(): void
    {
        $fixture = $this->journal58(
            ['resellable'],
            false
        );

        $this->actor(5801);

        try {
            app(CheckoutRmaInventory58Service::class)
                ->post($fixture['journal_id']);

            $this->fail(
                'Unshipped unit was accepted'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'shipped',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            20,
            $this->stock58($fixture)
        );

        $this->assertDatabaseCount(
            'inventory_receipts',
            0
        );
    }

    public function test_cod_rma58_damaged_unit_is_quarantined_not_sellable(): void
    {
        $fixture = $this->journal58(
            ['damaged']
        );

        $this->actor(5801);

        $result = app(
            CheckoutRmaInventory58Service::class
        )->post(
            $fixture['journal_id']
        );

        $this->assertSame(
            18,
            $this->stock58($fixture)
        );

        $this->assertSame(
            0,
            $result['sellable_units']
        );

        $this->assertSame(
            1,
            $result['quarantine_units']
        );

        $this->assertFalse(
            $result['inventory_restocked']
        );

        $this->assertDatabaseCount(
            'inventory_receipts',
            0
        );

        $this->assertDatabaseHas(
            'checkout_rma_stock_units58',
            [
                'disposition' => 'quarantine',
            ]
        );
    }

    public function test_cod_rma58_not_received_unit_does_not_create_receipt(): void
    {
        $fixture = $this->journal58([
            'resellable',
            'not_received',
        ]);

        $this->actor(5801);

        $result = app(
            CheckoutRmaInventory58Service::class
        )->post(
            $fixture['journal_id']
        );

        $this->assertSame(
            1,
            $result['accepted_units']
        );

        $this->assertSame(
            1,
            $result['sellable_units']
        );

        $this->assertDatabaseCount(
            'checkout_rma_stock_units58',
            1
        );

        $this->assertDatabaseCount(
            'inventory_receipts',
            1
        );

        $this->assertSame(
            19,
            $this->stock58($fixture)
        );
    }

    public function test_cod_rma58_inspector_cannot_post_own_case(): void
    {
        $fixture = $this->journal58();

        $this->actor(5306);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutRmaInventory58Service::class)
            ->post(
                $fixture['journal_id']
            );
    }

    public function test_cod_rma58_mutation_of_posted_batch_is_blocked(): void
    {
        $fixture = $this->journal58();

        $this->actor(5801);

        $result = app(
            CheckoutRmaInventory58Service::class
        )->post(
            $fixture['journal_id']
        );

        $this->expectException(
            QueryException::class
        );

        DB::table('checkout_rma_stock_batches58')
            ->where('id', $result['batch_id'])
            ->delete();
    }

    public function test_cod_rma58_stock_post_never_contacts_mono(): void
    {
        $fixture = $this->journal58();

        $this->replaceMonoHttpFake([]);

        $this->actor(5801);

        app(CheckoutRmaInventory58Service::class)
            ->post(
                $fixture['journal_id']
            );

        Http::assertNothingSent();
    }

    public function test_cod_rma58_api_routes_require_sanctum(): void
    {
        foreach ([
                     'rma58.post',
                     'rma58.show',
                 ] as $name) {
            $route = Route::getRoutes()
                ->getByName($name);

            $this->assertNotNull($route);

            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware()
            );
        }
    }
}
