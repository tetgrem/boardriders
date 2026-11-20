<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Services\Pricing\CheckoutMonoRefund57LoyaltyService;
use App\Services\Pricing\CheckoutRmaClosure59Service;
use App\Services\Pricing\CheckoutRmaInventory58Service;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

trait CheckoutRmaClosure59Cases
{
    /** @return array<string,int> */
    private function stocked59(
        array $conditions = ['resellable']
    ): array {
        $fixture = $this->journal58($conditions);

        $this->actor(5801);

        $batch = app(
            CheckoutRmaInventory58Service::class
        )->post($fixture['journal_id']);

        return [
            ...$fixture,
            'batch_id' => (int) $batch['batch_id'],
        ];
    }

    public function test_cod_rma59_closes_without_bonus_when_stock_and_provider_are_confirmed(): void
    {
        $fixture = $this->stocked59();

        $this->actor(5901);

        $svc = app(CheckoutRmaClosure59Service::class);

        $closed = $svc->close($fixture['journal_id']);

        $this->assertSame(
            'closed_pending_card_settlement',
            $closed['status']
        );

        $this->assertTrue($closed['rma_closed']);

        $this->assertFalse(
            $closed['card_settlement_confirmed']
        );

        $this->assertTrue(
            $closed['provider_refund_confirmed']
        );

        $this->assertTrue(
            $closed['inventory_accounted']
        );

        $this->assertTrue(
            $closed['loyalty_review_complete']
        );

        $this->assertSame(
            0,
            $closed['loyalty_postings_count']
        );

        $this->assertSame(
            $closed,
            $svc->show($closed['closure_id'])
        );

        $this->assertDatabaseCount(
            'checkout_rma_closures59',
            1
        );

        $this->assertDatabaseCount(
            'checkout_rma_closure_loyalty59',
            0
        );
    }

    public function test_cod_rma59_duplicate_close_is_idempotent(): void
    {
        $fixture = $this->stocked59();

        $this->actor(5901);

        $svc = app(
            CheckoutRmaClosure59Service::class
        );

        $first = $svc->close($fixture['journal_id']);
        $second = $svc->close($fixture['journal_id']);

        $this->assertSame($first, $second);

        $this->assertDatabaseCount(
            'checkout_rma_closures59',
            1
        );

        $this->assertDatabaseCount(
            'inventory_receipts',
            1
        );
    }

    public function test_cod_rma59_cannot_close_without_stock_posting(): void
    {
        $fixture = $this->journal58();

        $this->actor(5901);

        $this->expectException(
            DomainException::class
        );

        app(CheckoutRmaClosure59Service::class)
            ->close($fixture['journal_id']);
    }

    public function test_cod_rma59_requires_review_of_existing_purchase_grant(): void
    {
        $fixture = $this->stocked59();

        $key = (string) DB::table('orders')
            ->where('id', $fixture['order_id'])
            ->value('order_key');

        $this->purchaseGrant57($key);

        $this->actor(5901);

        $this->expectException(
            DomainException::class
        );

        app(CheckoutRmaClosure59Service::class)
            ->close($fixture['journal_id']);
    }

    public function test_cod_rma59_unposted_bonus_plan_blocks_close(): void
    {
        $fixture = $this->stocked59();

        $key = (string) DB::table('orders')
            ->where('id', $fixture['order_id'])
            ->value('order_key');

        $this->approvedEarned57([
            'journal_id' => $fixture['journal_id'],
            'order_key' => $key,
        ]);

        $this->actor(5901);

        $this->expectException(
            DomainException::class
        );

        app(CheckoutRmaClosure59Service::class)
            ->close($fixture['journal_id']);
    }

    public function test_cod_rma59_posted_bonus_allows_signed_closure(): void
    {
        $fixture = $this->stocked59();

        $key = (string) DB::table('orders')
            ->where('id', $fixture['order_id'])
            ->value('order_key');

        $approved = $this->approvedEarned57([
            'journal_id' => $fixture['journal_id'],
            'order_key' => $key,
        ]);

        $this->actor(5702);

        app(CheckoutMonoRefund57LoyaltyService::class)
            ->post($approved['plan_id']);

        $this->actor(5901);

        $closed = app(
            CheckoutRmaClosure59Service::class
        )->close($fixture['journal_id']);

        $this->assertSame(
            1,
            $closed['loyalty_postings_count']
        );

        $this->assertDatabaseCount(
            'checkout_rma_closure_loyalty59',
            1
        );

        $this->assertSame(
            20,
            (int) DB::table('loyalty_ledger_entries')
                ->where('grant_id', $approved['grant_id'])
                ->sum('pending_delta')
        );

        $this->assertDatabaseCount(
            'checkout_rma_closures59',
            1
        );
    }

    public function test_cod_rma59_requires_independent_ability(): void
    {
        $fixture = $this->stocked59();

        $this->actor(5801);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutRmaClosure59Service::class)
            ->close($fixture['journal_id']);
    }

    public function test_cod_rma59_closure_is_immutable_in_database(): void
    {
        $fixture = $this->stocked59();

        $this->actor(5901);

        $closed = app(
            CheckoutRmaClosure59Service::class
        )->close($fixture['journal_id']);

        $this->expectException(
            QueryException::class
        );

        DB::table('checkout_rma_closures59')
            ->where('id', $closed['closure_id'])
            ->update([
                'closed_by' => 123,
            ]);
    }

    public function test_cod_rma59_damaged_goods_can_close_without_sellable_receipt(): void
    {
        $fixture = $this->stocked59([
            'damaged',
        ]);

        $this->actor(5901);

        $closed = app(
            CheckoutRmaClosure59Service::class
        )->close($fixture['journal_id']);

        $this->assertSame(
            0,
            $closed['sellable_units']
        );

        $this->assertSame(
            1,
            $closed['quarantine_units']
        );

        $this->assertDatabaseCount(
            'inventory_receipts',
            0
        );
    }

    public function test_cod_rma59_closing_never_contacts_mono(): void
    {
        $fixture = $this->stocked59();

        $this->replaceMonoHttpFake([]);

        $this->actor(5901);

        app(CheckoutRmaClosure59Service::class)
            ->close($fixture['journal_id']);

        Http::assertNothingSent();
    }

    public function test_cod_rma59_routes_require_sanctum(): void
    {
        foreach ([
                     'rma59.close',
                     'rma59.show',
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
