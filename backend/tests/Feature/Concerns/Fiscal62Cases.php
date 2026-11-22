<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Jobs\Fiscal62SendJob;
use App\Services\Fiscal62\Fiscal62Access;
use App\Services\Fiscal62\Fiscal62Integrity;
use App\Services\Fiscal62\Fiscal62Profiles;
use App\Services\Fiscal62\Fiscal62StatusService;
use App\Services\Fiscal62\Fiscal62TicketService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * Reuses CheckoutMonoRefund54Test and Fiscal61Cases test fixtures.
 * All live flags and external HTTP responses are overridden in tests only.
 */
trait Fiscal62Cases
{
    /** @return array<string,mixed> */
    private function fiscal62SaleFixture(): array
    {
        $sale = $this->saleFixture61();

        $this->actor(6201);
        app(Fiscal62Profiles::class)->approve(
            $this->variantId,
            'BR62-TEST',
            'Boardriders fiscal test variant',
            1
        );

        $this->actor(6202);
        $ticket = app(Fiscal62TicketService::class)->prepare(
            (int) $sale['document']['document_id']
        );

        return [
            ...$sale,
            'ticket' => $ticket,
            'ticket_id' => (int) $ticket['ticket_id'],
            'provider_id' => (string) $ticket['provider_receipt_id'],
        ];
    }

    /** Never enable these settings in production on the basis of tests. */
    private function enableFiscal62TestTransport(): void
    {
        config()->set('fiscal62.enabled', true);
        config()->set('fiscal62.live_enabled', true);
        config()->set('fiscal62.receipt_contract_certified', true);
        config()->set('fiscal62.merchant_tax_reviewed', true);
        config()->set('fiscal62.mono_auto_fiscalization_disabled', true);
        config()->set('fiscal62.checkbox.token', 'fiscal62-fake-token');
    }

    /** @return array<string,mixed> */
    private function fiscal62UncertainFixture(): array
    {
        $fixture = $this->fiscal62SaleFixture();
        $this->enableFiscal62TestTransport();

        $this->replaceMonoHttpFake([
            'https://api.checkbox.ua/api/v1/receipts/sell' =>
                Http::response(['status' => 'PENDING'], 202),
        ]);

        $sent = app(Fiscal62TicketService::class)->sendOnce(
            $fixture['ticket_id'],
            6203
        );

        $this->assertSame('uncertain', $sent['status']);
        $this->assertTrue($sent['attempted']);

        return $fixture;
    }

    public function test_cod_fiscal62_approved_product_profile_is_idempotent(): void
    {
        $this->saleFixture61();
        $this->actor(6201);
        $service = app(Fiscal62Profiles::class);

        $first = $service->approve(
            $this->variantId,
            'BR62-A',
            'Approved fiscal name',
            1
        );
        $second = $service->approve(
            $this->variantId,
            'BR62-A',
            'Approved fiscal name',
            1
        );

        $this->assertSame($first, $second);
        $this->assertSame($first, $service->read($this->variantId));
        $this->assertDatabaseCount('checkout_fiscal_profiles62', 1);
    }

    public function test_cod_fiscal62_cannot_silently_change_approved_tax_profile(): void
    {
        $this->saleFixture61();
        $this->actor(6201);
        $service = app(Fiscal62Profiles::class);
        $service->approve($this->variantId, 'BR62-B', 'Original name', 1);

        $this->expectException(DomainException::class);
        $service->approve($this->variantId, 'BR62-B', 'Changed tax name', 2);
    }

    public function test_cod_fiscal62_profile_approval_requires_special_ability(): void
    {
        $this->saleFixture61();
        $this->actor(6202);

        $this->expectException(AuthorizationException::class);
        app(Fiscal62Profiles::class)->approve(
            $this->variantId,
            'BR62-C',
            'Not authorized',
            1
        );
    }

    public function test_cod_fiscal62_prepared_sale_has_fixed_signed_checkbox_uuid_and_money(): void
    {
        $fixture = $this->fiscal62SaleFixture();
        $ticket = $fixture['ticket'];

        $this->assertSame('prepared', $ticket['status']);
        $this->assertFalse($ticket['attempted']);
        $this->assertNull($ticket['related_receipt_id']);
        $this->assertSame(
            $fixture['provider_id'],
            $ticket['request']['id']
        );
        $this->assertSame(
            2000,
            $ticket['request']['payments'][0]['value']
        );
        $this->assertCount(2, $ticket['request']['goods']);
        $this->assertSame(
            'BR62-TEST',
            $ticket['request']['goods'][0]['good']['code']
        );
        $this->assertDatabaseCount('checkout_fiscal_tickets62', 1);
        $this->assertDatabaseCount('checkout_fiscal_events62', 1);
    }

    public function test_cod_fiscal62_ticket_preparation_replay_has_no_new_uuid(): void
    {
        $fixture = $this->fiscal62SaleFixture();
        $this->actor(6202);
        $second = app(Fiscal62TicketService::class)->prepare(
            (int) $fixture['document']['document_id']
        );

        $this->assertSame($fixture['ticket'], $second);
        $this->assertDatabaseCount('checkout_fiscal_tickets62', 1);
        $this->assertDatabaseCount('checkout_fiscal_events62', 1);
    }

    public function test_cod_fiscal62_preparation_requires_approved_tax_profile(): void
    {
        $sale = $this->saleFixture61();
        $this->actor(6202);

        $this->expectException(DomainException::class);
        app(Fiscal62TicketService::class)->prepare(
            (int) $sale['document']['document_id']
        );
    }

    public function test_cod_fiscal62_refund_requires_done_original_sale(): void
    {
        $fixture = $this->refundFixture61();
        $this->actor(6201);
        app(Fiscal62Profiles::class)->approve(
            $this->variantId,
            'BR62-RET',
            'Fiscal return test item',
            1
        );

        $this->actor(6202);
        $service = app(Fiscal62TicketService::class);
        $service->prepare((int) $fixture['sale_document']['document_id']);

        $this->expectException(DomainException::class);
        $service->prepare((int) $fixture['refund_document']['document_id']);
    }

    public function test_cod_fiscal62_live_send_fails_closed_by_default(): void
    {
        $fixture = $this->fiscal62SaleFixture();
        $this->actor(6203);
        Queue::fake();

        $this->expectException(DomainException::class);
        app(Fiscal62TicketService::class)->requestSend($fixture['ticket_id']);
    }

    public function test_cod_fiscal62_sender_must_differ_from_preparer(): void
    {
        $fixture = $this->fiscal62SaleFixture();
        $this->enableFiscal62TestTransport();
        Gate::define(
            Fiscal62Access::SEND,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [6202, 6203],
                true
            )
        );

        $this->actor(6202);
        $this->expectException(DomainException::class);
        app(Fiscal62TicketService::class)->requestSend($fixture['ticket_id']);
    }

    public function test_cod_fiscal62_unknown_post_never_retransmits_money_or_fiscal_request(): void
    {
        $fixture = $this->fiscal62UncertainFixture();
        $service = app(Fiscal62TicketService::class);
        $again = $service->sendOnce($fixture['ticket_id'], 6203);

        $this->assertSame('uncertain', $again['status']);
        $this->assertTrue($again['attempted']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('checkout_fiscal_tickets62', 1);

        $events = app(Fiscal62Integrity::class)->events($fixture['ticket_id']);
        $this->assertCount(3, $events);
        $this->assertSame('post_claimed', $events[1]['kind']);
    }

    public function test_cod_fiscal62_pending_get_does_not_issue_fiscal_receipt(): void
    {
        $fixture = $this->fiscal62UncertainFixture();
        $this->replaceMonoHttpFake([
            'https://api.checkbox.ua/api/v1/receipts/*' => Http::response([
                'id' => $fixture['provider_id'],
                'status' => 'PENDING',
            ], 200),
        ]);

        $checked = app(Fiscal62StatusService::class)->poll(
            $fixture['ticket_id'],
            6204
        );

        $this->assertSame('provider_pending', $checked['status']);
        $this->assertNull($checked['fiscal_code']);
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
    }

    public function test_cod_fiscal62_done_without_fiscal_evidence_needs_review(): void
    {
        $fixture = $this->fiscal62UncertainFixture();
        $this->replaceMonoHttpFake([
            'https://api.checkbox.ua/api/v1/receipts/*' => Http::response([
                'id' => $fixture['provider_id'],
                'status' => 'DONE',
                'total_payment' => 2000,
                'is_sent_dps' => true,
                'transaction' => ['response_status' => 'OK'],
                // No verified fiscal_code.
            ], 200),
        ]);

        $result = app(Fiscal62StatusService::class)->poll(
            $fixture['ticket_id'],
            6204
        );
        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['fiscal_code']);
    }

    public function test_cod_fiscal62_valid_done_get_is_irreversible(): void
    {
        $fixture = $this->fiscal62UncertainFixture();
        $this->replaceMonoHttpFake([
            'https://api.checkbox.ua/api/v1/receipts/*' => Http::response([
                'id' => $fixture['provider_id'],
                'status' => 'DONE',
                'fiscal_code' => 'FISCAL-62-0001',
                'is_sent_dps' => true,
                'total_payment' => 2000,
                'transaction' => ['response_status' => 'OK'],
            ], 200),
        ]);

        $service = app(Fiscal62StatusService::class);
        $done = $service->poll($fixture['ticket_id'], 6204);
        $repeat = $service->poll($fixture['ticket_id'], 6204);

        $this->assertSame('done', $done['status']);
        $this->assertSame('FISCAL-62-0001', $done['fiscal_code']);
        $this->assertSame($done, $repeat);
        Http::assertSentCount(1);
    }

    public function test_cod_fiscal62_done_with_wrong_total_is_not_accepted(): void
    {
        $fixture = $this->fiscal62UncertainFixture();
        $this->replaceMonoHttpFake([
            'https://api.checkbox.ua/api/v1/receipts/*' => Http::response([
                'id' => $fixture['provider_id'],
                'status' => 'DONE',
                'fiscal_code' => 'FISCAL-62-FAIL',
                'is_sent_dps' => true,
                'total_payment' => 1999,
                'transaction' => ['response_status' => 'OK'],
            ], 200),
        ]);

        $result = app(Fiscal62StatusService::class)->poll(
            $fixture['ticket_id'],
            6204
        );

        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['fiscal_code']);
    }

    public function test_cod_fiscal62_ticket_identity_is_immutable_in_postgresql(): void
    {
        $fixture = $this->fiscal62SaleFixture();
        $this->expectException(QueryException::class);

        DB::table('checkout_fiscal_tickets62')
            ->where('id', $fixture['ticket_id'])
            ->update(['request_json' => '{}']);
    }

    public function test_cod_fiscal62_queue_send_does_not_send_http_synchronously(): void
    {
        $fixture = $this->fiscal62SaleFixture();
        $this->enableFiscal62TestTransport();
        $this->replaceMonoHttpFake([]);
        Queue::fake();

        $this->actor(6203);
        $queued = app(Fiscal62TicketService::class)
            ->requestSend($fixture['ticket_id']);

        $this->assertTrue($queued['queue_requested']);
        Queue::assertPushed(Fiscal62SendJob::class, 1);
        Http::assertNothingSent();
        $this->assertDatabaseCount('checkout_fiscal_events62', 1);
    }

    public function test_cod_fiscal62_api_routes_require_sanctum(): void
    {
        foreach ([
                     'fiscal62.profile.approve',
                     'fiscal62.ticket.prepare',
                     'fiscal62.ticket.send',
                     'fiscal62.ticket.poll',
                     'fiscal62.ticket.show',
                     'fiscal62.ticket.index',
                     'fiscal62.statistics',
                 ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, 'Route missing: ' . $name);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
