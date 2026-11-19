<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Services\Pricing\CheckoutMonoRefund54Service;
use App\Services\Pricing\CheckoutMonoRefund56JournalService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/** Uses the paid-order + approved-RMA fixture in CheckoutMonoRefund54Test. */
trait CheckoutMonoRefund56Cases
{
    /** @return array{execution_id:int,order_key:string} */
    private function providerSuccess56(): array
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response($this->invoice($fixture['order_key'], [[
                    'extRef' => $sent['ext_ref'],
                    'status' => 'success',
                    'amount' => 1000,
                    'ccy' => 980,
                ]])),
        ]);

        $this->actor(5402);

        $confirmed = app(CheckoutMonoRefund54Service::class)
            ->reconcile($sent['execution_id']);

        $this->assertSame('succeeded', $confirmed['status']);

        return [
            'execution_id' => $sent['execution_id'],
            'order_key' => $fixture['order_key'],
        ];
    }

    public function test_cod_mono56_verified_success_creates_journal_and_two_review_handoffs(): void
    {
        $fixture = $this->providerSuccess56();
        $this->actor(5601);

        $result = app(CheckoutMonoRefund56JournalService::class)
            ->record($fixture['execution_id']);

        $this->assertSame('provider_confirmed', $result['status']);
        $this->assertSame(1000, $result['amount_minor']);
        $this->assertTrue($result['provider_refund_confirmed']);
        $this->assertFalse($result['card_settlement_confirmed']);
        $this->assertFalse($result['loyalty_adjusted']);
        $this->assertFalse($result['inventory_restocked']);
        $this->assertFalse($result['rma_closed']);
        $this->assertSame(
            ['inventory_review', 'loyalty_review'],
            $result['pending_review_topics']
        );
        $this->assertDatabaseCount('checkout_mono_refund_journal56', 1);
        $this->assertDatabaseCount('checkout_mono_refund_handoffs56', 2);
    }

    public function test_cod_mono56_record_replay_is_idempotent(): void
    {
        $fixture = $this->providerSuccess56();
        $this->actor(5601);
        $service = app(CheckoutMonoRefund56JournalService::class);

        $first = $service->record($fixture['execution_id']);
        $second = $service->record($fixture['execution_id']);

        $this->assertSame($first['journal_id'], $second['journal_id']);
        $this->assertDatabaseCount('checkout_mono_refund_journal56', 1);
        $this->assertDatabaseCount('checkout_mono_refund_handoffs56', 2);
    }

    public function test_cod_mono56_pending_refund_must_not_enter_journal(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];
        $this->actor(5601);

        $this->expectException(DomainException::class);

        app(CheckoutMonoRefund56JournalService::class)
            ->record($sent['execution_id']);
    }

    public function test_cod_mono56_wrong_provider_amount_must_not_enter_journal(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $this->replaceMonoHttpFake([
            'https://api.monobank.ua/api/merchant/invoice/status*' =>
                Http::response($this->invoice($fixture['order_key'], [[
                    'extRef' => $sent['ext_ref'],
                    'status' => 'success',
                    'amount' => 999,
                    'ccy' => 980,
                ]])),
        ]);

        $this->actor(5402);
        $failed = app(CheckoutMonoRefund54Service::class)
            ->reconcile($sent['execution_id']);

        $this->assertSame('needs_review', $failed['status']);

        $this->actor(5601);
        $this->expectException(DomainException::class);

        app(CheckoutMonoRefund56JournalService::class)
            ->record($sent['execution_id']);
    }

    public function test_cod_mono56_journal_requires_distinct_finance_ability(): void
    {
        $fixture = $this->providerSuccess56();
        $this->actor(5402);
        $this->expectException(AuthorizationException::class);

        app(CheckoutMonoRefund56JournalService::class)
            ->record($fixture['execution_id']);
    }

    public function test_cod_mono56_journal_is_immutable_in_postgres(): void
    {
        $fixture = $this->providerSuccess56();
        $this->actor(5601);

        $result = app(CheckoutMonoRefund56JournalService::class)
            ->record($fixture['execution_id']);

        $this->expectException(QueryException::class);

        DB::table('checkout_mono_refund_journal56')
            ->where('id', $result['journal_id'])
            ->delete();
    }

    public function test_cod_mono56_review_handoff_is_immutable_in_postgres(): void
    {
        $fixture = $this->providerSuccess56();
        $this->actor(5601);

        app(CheckoutMonoRefund56JournalService::class)
            ->record($fixture['execution_id']);

        $this->expectException(QueryException::class);

        DB::table('checkout_mono_refund_handoffs56')
            ->where('topic', 'loyalty_review')
            ->update(['topic' => 'inventory_review']);
    }

    public function test_cod_mono56_database_rejects_unverified_journal_insert(): void
    {
        $fixture = $this->approvedPlan();
        $sent = $this->submit($fixture)['result'];

        $execution = DB::table('checkout_mono_refund_executions')
            ->where('id', $sent['execution_id'])
            ->first();

        $observationId = (int) DB::table('checkout_mono_refund_observations')
            ->where('execution_id', $execution->id)
            ->value('id');

        $this->expectException(QueryException::class);

        DB::table('checkout_mono_refund_journal56')->insert([
            'execution_id' => $execution->id,
            'refund_plan_id' => $execution->refund_plan_id,
            'order_id' => $execution->order_id,
            'capture_receipt_id' => $execution->capture_receipt_id,
            'observation_id' => $observationId,
            'ext_ref' => $execution->ext_ref,
            'amount_minor' => $execution->amount_minor,
            'currency_code' => 'UAH',
            'recorded_by' => 5601,
            'entry_hmac' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    public function test_cod_mono56_journal_read_verifies_signed_record(): void
    {
        $fixture = $this->providerSuccess56();
        $this->actor(5601);
        $service = app(CheckoutMonoRefund56JournalService::class);
        $first = $service->record($fixture['execution_id']);
        $read = $service->show($first['journal_id']);

        $this->assertSame($first, $read);
    }

    public function test_cod_mono56_journal_never_calls_mono_provider(): void
    {
        $fixture = $this->providerSuccess56();
        $this->replaceMonoHttpFake([]);
        $this->actor(5601);

        app(CheckoutMonoRefund56JournalService::class)
            ->record($fixture['execution_id']);

        Http::assertNothingSent();
    }

    public function test_cod_mono56_journal_routes_require_sanctum(): void
    {
        foreach (['mono56.record', 'mono56.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
