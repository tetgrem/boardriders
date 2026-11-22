<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Services\Crm64\Crm64Access;
use App\Services\Crm64\Crm64Card;
use App\Services\Crm64\Crm64Dashboard;
use App\Services\Crm64\Crm64Notes;
use App\Services\Crm64\Crm64Orders;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Integration with the paid-order / RMA / Mono / Fiscal61-63 test fixtures. */
trait Crm64Cases
{
    private function crm64Paid(): array
    {
        $order = $this->paidOrder();
        $this->actor(6401);
        return $order;
    }

    public function test_cod_crm64_list_contains_paid_order(): void
    {
        $order = $this->crm64Paid();
        $page = app(Crm64Orders::class)->page(['limit' => 10]);
        $this->assertCount(1, $page['items']);
        $this->assertSame($order['order_key'], $page['items'][0]['order_key']);
        $this->assertSame('paid', $page['items'][0]['payment_status']);
    }

    public function test_cod_crm64_order_key_prefix_search(): void
    {
        $order = $this->crm64Paid();
        $prefix = substr($order['order_key'], 0, 8);
        $match = app(Crm64Orders::class)->page(['order_key' => $prefix]);
        $none = app(Crm64Orders::class)->page(['order_key' => 'ffffffff-ffff']);
        $this->assertCount(1, $match['items']);
        $this->assertCount(0, $none['items']);
    }

    public function test_cod_crm64_cursor_pages_order_ids_without_duplicates(): void
    {
        $first = $this->paidOrder();
        $other = app(\App\Services\Pricing\CheckoutOrderPreparationService::class)->prepare(
            (string) Str::uuid(),
            [['variant_id' => $this->variantId, 'quantity' => 1]],
            'UA', 'UA_RETAIL', CarbonImmutable::now('UTC')->addMinutes(30)
        );
        $this->actor(6401);
        $service = app(Crm64Orders::class);
        $top = $service->page(['limit' => 1]);
        $next = $service->page(['limit' => 1, 'before_id' => $top['next_before_id']]);
        $this->assertCount(1, $top['items']);
        $this->assertCount(1, $next['items']);
        $this->assertNotSame($top['items'][0]['order_id'], $next['items'][0]['order_id']);
        $this->assertSame($other['order_key'], $top['items'][0]['order_key']);
        $this->assertSame($first['order_key'], $next['items'][0]['order_key']);
    }

    public function test_cod_crm64_list_rma_filter_only_matches_return_orders(): void
    {
        $fixture = $this->approvedPlan();
        $this->actor(6401);
        $service = app(Crm64Orders::class);
        $this->assertCount(1, $service->page(['has_rma' => true])['items']);
        $this->assertSame([], $service->page(['has_rma' => false])['items']);
    }

    public function test_cod_crm64_fiscal_issue_filter_requires_fiscal_role(): void
    {
        $this->crm64Paid();
        $this->expectException(AuthorizationException::class);
        app(Crm64Orders::class)->page(['has_fiscal_issue' => true]);
    }

    public function test_cod_crm64_bad_order_prefix_is_rejected(): void
    {
        $this->actor(6401);
        $this->expectException(DomainException::class);
        app(Crm64Orders::class)->page(['order_key' => '%']);
    }

    public function test_cod_crm64_list_redacts_money_without_finance_permission(): void
    {
        $this->crm64Paid();
        $row = app(Crm64Orders::class)->page([])['items'][0];
        $this->assertNull($row['goods_payable_minor']);
        $this->assertNull($row['paid_capture_count']);
        $this->assertNull($row['journal_count']);
        $this->assertNull($row['open_fiscal_case_count']);
    }

    public function test_cod_crm64_card_is_read_only_and_logs_access(): void
    {
        $order = $this->crm64Paid();
        $card = app(Crm64Card::class)->show($order['order_key']);
        $this->assertSame($order['order_key'], $card['order_key']);
        $this->assertTrue($card['read_only_projection']);
        $this->assertTrue($card['finance']['restricted']);
        $this->assertTrue($card['returns']['restricted']);
        $this->assertDatabaseCount('checkout_crm_access64', 1);
    }

    public function test_cod_crm64_card_requires_valid_existing_order(): void
    {
        $this->actor(6401);
        $this->expectException(DomainException::class);
        app(Crm64Card::class)->show((string) Str::uuid());
    }

    public function test_cod_crm64_finance_has_verified_original_capture(): void
    {
        $order = $this->paidOrder();
        $this->actor(6402);
        $money = app(Crm64Card::class)->finance($order['order_key']);
        $this->assertSame(2000, $money['financial_snapshot']['goods_payable_minor']);
        $this->assertSame(2000, $money['accepted_capture_total_minor']);
        $this->assertSame(0, $money['provider_confirmed_refund_minor']);
        $this->assertFalse($money['card_settlement_confirmed']);
    }

    public function test_cod_crm64_finance_denied_without_separate_ability(): void
    {
        $order = $this->crm64Paid();
        $this->expectException(AuthorizationException::class);
        app(Crm64Card::class)->finance($order['order_key']);
    }

    public function test_cod_crm64_confirmed_refund_has_signed_journal_projection(): void
    {
        $fixture = $this->journal58();
        $this->actor(6402);
        $orderKey = (string) DB::table('orders')->where('id', $fixture['order_id'])->value('order_key');
        $money = app(Crm64Card::class)->finance($orderKey);
        $this->assertSame(1000, $money['provider_confirmed_refund_minor']);
        $this->assertSame(1000, $money['journaled_refund_minor']);
        $this->assertSame($fixture['journal_id'], $money['refunds'][0]['journal_id']);
        $this->assertFalse($money['refunds'][0]['card_settlement_confirmed']);
    }

    public function test_cod_crm64_note_is_idempotent_for_same_uuid(): void
    {
        $order = $this->paidOrder();
        $this->actor(6404);
        $service = app(Crm64Notes::class);
        $key = (string) Str::uuid();
        $first = $service->create($order['order_key'], $key, 'service', 'Manager contacted customer');
        $again = $service->create($order['order_key'], $key, 'service', 'Manager contacted customer');
        $this->assertSame($first, $again);
        $this->assertDatabaseCount('checkout_crm_notes64', 1);
    }

    public function test_cod_crm64_note_key_conflict_is_rejected(): void
    {
        $order = $this->paidOrder();
        $this->actor(6404);
        $service = app(Crm64Notes::class);
        $key = (string) Str::uuid();
        $service->create($order['order_key'], $key, 'service', 'Original service comment');
        $this->expectException(DomainException::class);
        $service->create($order['order_key'], $key, 'service', 'Conflicting service comment');
    }

    public function test_cod_crm64_notes_require_staff_write_permission(): void
    {
        $order = $this->crm64Paid();
        $this->expectException(AuthorizationException::class);
        app(Crm64Notes::class)->create($order['order_key'], (string) Str::uuid(), 'service', 'Unauthorized change');
    }

    public function test_cod_crm64_postgres_cannot_change_crm_note(): void
    {
        $order = $this->paidOrder();
        $this->actor(6404);
        $note = app(Crm64Notes::class)->create($order['order_key'], (string) Str::uuid(), 'service', 'Immutable audit comment');
        $this->expectException(QueryException::class);
        DB::table('checkout_crm_notes64')->where('id', $note['note_id'])->update(['body' => 'Changed after approval']);
    }

    public function test_cod_crm64_timeline_includes_signed_notes(): void
    {
        $order = $this->paidOrder();
        $this->actor(6404);
        app(Crm64Notes::class)->create($order['order_key'], (string) Str::uuid(), 'delivery', 'Call recipient about address');
        $this->actor(6401);
        $timeline = app(Crm64Card::class)->timeline($order['order_key']);
        $this->assertSame('crm_note', $timeline['items'][0]['source']);
        $this->assertFalse($timeline['external_requests_sent'] > 0);
        $this->assertDatabaseCount('checkout_crm_access64', 1);
    }

    public function test_cod_crm64_access_history_verifies_signed_read_log(): void
    {
        $order = $this->paidOrder();
        $this->actor(6401);
        app(Crm64Card::class)->show($order['order_key']);
        $this->actor(6405);
        $events = app(Crm64Card::class)->accessHistory($order['order_key']);
        $this->assertCount(1, $events);
        $this->assertSame('card', $events[0]['operation']);
        $this->assertSame(6401, $events[0]['actor_id']);
    }

    public function test_cod_crm64_fiscal_drafts_require_independent_fiscal_view(): void
    {
        $fixture = $this->saleFixture61();
        $this->actor(6403);
        $card = app(Crm64Card::class)->show($fixture['order']['order_key']);
        $this->assertFalse($card['fiscal']['restricted']);
        $this->assertCount(1, $card['fiscal']['documents']);
        $this->assertSame('sale', $card['fiscal']['documents'][0]['kind']);
        $this->assertNull($card['fiscal']['documents'][0]['ticket']);
    }

    public function test_cod_crm64_dashboard_is_permission_scoped(): void
    {
        $this->paidOrder();
        $this->actor(6405);
        $stats = app(Crm64Dashboard::class)->summary();
        $this->assertSame(1, $stats['orders_total']);
        $this->assertSame(1, $stats['orders_by_payment_status']['paid']);
        $this->assertNull($stats['confirmed_refund_journal_count']);
    }

    public function test_cod_crm64_feature_flag_fails_closed(): void
    {
        config()->set('crm64.enabled', false);
        $this->actor(6401);
        $this->expectException(DomainException::class);
        app(Crm64Orders::class)->page([]);
    }

    public function test_cod_crm64_api_routes_are_sanctum_protected(): void
    {
        foreach ([
            'crm64.orders.index', 'crm64.orders.show', 'crm64.orders.finance',
            'crm64.orders.timeline', 'crm64.orders.notes', 'crm64.orders.access',
            'crm64.dashboard',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, 'Route missing: ' . $name);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
