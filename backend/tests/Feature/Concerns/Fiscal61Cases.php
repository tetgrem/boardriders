<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Jobs\Fiscal61PreviewJob;
use App\Services\Fiscal61\Fiscal61Authorization;
use App\Services\Fiscal61\Fiscal61IncidentService;
use App\Services\Fiscal61\Fiscal61PreparationService;
use App\Services\Fiscal61\Fiscal61ReportService;
use App\Services\Fiscal61\Fiscal61SimulationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

trait Fiscal61Cases
{
    private function captureId61(int $orderId): int
    {
        return (int) DB::table(
            'checkout_payment_capture_receipts'
        )
            ->where('order_id', $orderId)
            ->where('provider_code', 'mono')
            ->value('id');
    }

    private function saleFixture61(): array
    {
        $order = $this->paidOrder();

        $captureId = $this->captureId61(
            (int) $order['order_id']
        );

        $this->actor(6101);

        $document = app(
            Fiscal61PreparationService::class
        )->prepareSale($captureId);

        return [
            'order' => $order,
            'capture_id' => $captureId,
            'document' => $document,
        ];
    }

    private function refundFixture61(
        array $conditions = ['resellable']
    ): array {
        $fixture = $this->journal58($conditions);

        $captureId = $this->captureId61(
            $fixture['order_id']
        );

        $this->actor(6101);

        $service = app(
            Fiscal61PreparationService::class
        );

        $sale = $service->prepareSale(
            $captureId
        );

        $refund = $service->prepareRefund(
            $fixture['journal_id']
        );

        return [
            ...$fixture,
            'capture_id' => $captureId,
            'sale_document' => $sale,
            'refund_document' => $refund,
        ];
    }

    // ---------------------------------------------------------
    // 01 — SALE PREPARATION
    // ---------------------------------------------------------

    public function test_cod_fiscal61_paid_sale_is_idempotent(): void
    {
        $fixture = $this->saleFixture61();

        $this->actor(6101);

        $again = app(
            Fiscal61PreparationService::class
        )->prepareSale($fixture['capture_id']);

        $this->assertSame(
            $fixture['document'],
            $again
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_documents61',
            1
        );

        $this->assertSame(
            'prepared_non_fiscal',
            $again['status']
        );
    }

    // ---------------------------------------------------------
    // 02 — SALE MONETARY TOTALS
    // ---------------------------------------------------------

    public function test_cod_fiscal61_sale_uses_immutable_financial_snapshot(): void
    {
        $fixture = $this->saleFixture61();

        $document = $fixture['document'];

        $this->assertSame(
            'sale',
            $document['kind']
        );

        $this->assertSame(
            2000,
            $document['amount_minor']
        );

        $this->assertSame(
            2000,
            $document['payload']['amount_minor']
        );

        $this->assertCount(
            2,
            $document['payload']['items']
        );

        $this->assertSame(
            'not_configured',
            $document['payload']['tax_mapping']
        );

        $this->assertFalse(
            $document['is_fiscal_receipt']
        );

        $this->assertNull(
            $document['fiscal_number']
        );
    }

    // ---------------------------------------------------------
    // 03 — PREPARATION FEATURE FLAG
    // ---------------------------------------------------------

    public function test_cod_fiscal61_disabled_preparation_fails_closed(): void
    {
        $order = $this->paidOrder();

        $captureId = $this->captureId61(
            $order['order_id']
        );

        config()->set('fiscal61.enabled', false);

        $this->actor(6101);

        $this->expectException(
            DomainException::class
        );

        app(Fiscal61PreparationService::class)
            ->prepareSale($captureId);
    }

    // ---------------------------------------------------------
    // 04 — STAFF AUTHORIZATION
    // ---------------------------------------------------------

    public function test_cod_fiscal61_unprivileged_employee_cannot_prepare(): void
    {
        $order = $this->paidOrder();

        $captureId = $this->captureId61(
            $order['order_id']
        );

        $this->actor(5305);

        $this->expectException(
            AuthorizationException::class
        );

        app(Fiscal61PreparationService::class)
            ->prepareSale($captureId);
    }

    // ---------------------------------------------------------
    // 05 — REFUND REQUIRES ORIGINAL SALE
    // ---------------------------------------------------------

    public function test_cod_fiscal61_refund_requires_original_sale_draft(): void
    {
        $fixture = $this->journal58();

        $this->actor(6101);

        $this->expectException(
            DomainException::class
        );

        app(Fiscal61PreparationService::class)
            ->prepareRefund($fixture['journal_id']);
    }

    // ---------------------------------------------------------
    // 06 — VERIFIED PARTIAL REFUND
    // ---------------------------------------------------------

    public function test_cod_fiscal61_partial_refund_is_linked_to_sale(): void
    {
        $fixture = $this->refundFixture61();

        $sale = $fixture['sale_document'];
        $refund = $fixture['refund_document'];

        $this->assertSame(
            'refund',
            $refund['kind']
        );

        $this->assertSame(
            $sale['document_id'],
            $refund['sale_document_id']
        );

        $this->assertSame(
            $sale['capture_receipt_id'],
            $refund['capture_receipt_id']
        );

        $this->assertSame(
            1000,
            $refund['amount_minor']
        );

        $this->assertCount(
            1,
            $refund['payload']['items']
        );

        $this->assertTrue(
            $refund['payload'][
            'requires_original_fiscal_number'
            ]
        );

        $this->assertFalse(
            $refund['is_fiscal_receipt']
        );
    }

    // ---------------------------------------------------------
    // 07 — REFUND IDEMPOTENCY
    // ---------------------------------------------------------

    public function test_cod_fiscal61_replayed_refund_does_not_duplicate_draft(): void
    {
        $fixture = $this->refundFixture61();

        $this->actor(6101);

        $again = app(
            Fiscal61PreparationService::class
        )->prepareRefund($fixture['journal_id']);

        $this->assertSame(
            $fixture['refund_document'],
            $again
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_documents61',
            2
        );
    }

    // ---------------------------------------------------------
    // 08 — UNRECEIVED UNIT EXCLUDED
    // ---------------------------------------------------------

    public function test_cod_fiscal61_unreceived_unit_is_not_refunded_in_draft(): void
    {
        $fixture = $this->refundFixture61([
            'resellable',
            'not_received',
        ]);

        $refund = $fixture['refund_document'];

        $this->assertSame(
            1000,
            $refund['amount_minor']
        );

        $this->assertCount(
            1,
            $refund['payload']['items']
        );

        $this->assertSame(
            'resellable',
            $refund['payload']['items'][0]['condition']
        );
    }

    // ---------------------------------------------------------
    // 09 — DATABASE IMMUTABILITY
    // ---------------------------------------------------------

    public function test_cod_fiscal61_database_cannot_modify_prepared_document(): void
    {
        $fixture = $this->saleFixture61();

        $this->expectException(
            QueryException::class
        );

        DB::table('checkout_fiscal_documents61')
            ->where(
                'id',
                $fixture['document']['document_id']
            )
            ->update([
                'amount_minor' => 999999,
            ]);
    }

    // ---------------------------------------------------------
    // 10 — QUEUE DISPATCH
    // ---------------------------------------------------------

    public function test_cod_fiscal61_preview_is_queued_without_fiscal_post(): void
    {
        $fixture = $this->saleFixture61();

        Queue::fake();

        $this->actor(6102);

        $result = app(
            Fiscal61SimulationService::class
        )->requestPreview(
            $fixture['document']['document_id']
        );

        $this->assertSame(
            'queued_preview',
            $result['status']
        );

        $this->assertFalse(
            $result['is_fiscal_receipt']
        );

        Queue::assertPushed(
            Fiscal61PreviewJob::class,
            1
        );
    }

    // ---------------------------------------------------------
    // 11 — QUEUE WORKER: NO NETWORK REQUESTS
    // ---------------------------------------------------------

    public function test_cod_fiscal61_preview_worker_never_contacts_provider(): void
    {
        $fixture = $this->saleFixture61();

        $documentId = $fixture['document']['document_id'];

        $this->replaceMonoHttpFake([]);

        $job = new Fiscal61PreviewJob(
            $documentId,
            6102
        );

        $job->handle(
            app(Fiscal61SimulationService::class)
        );

        Http::assertNothingSent();

        $this->actor(6103);

        $result = app(
            Fiscal61SimulationService::class
        )->show($documentId);

        $this->assertSame(
            'preview_only',
            $result['status']
        );

        $this->assertFalse(
            $result['is_fiscal_receipt']
        );

        $this->assertNull(
            $result['fiscal_number']
        );

        $this->assertFalse(
            $result['provider_submission_performed']
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_simulations61',
            1
        );
    }

    // ---------------------------------------------------------
    // 12 — QUEUE REPLAY
    // ---------------------------------------------------------

    public function test_cod_fiscal61_preview_worker_is_idempotent(): void
    {
        $fixture = $this->saleFixture61();

        $documentId = $fixture['document']['document_id'];

        $service = app(
            Fiscal61SimulationService::class
        );

        $first = $service->process(
            $documentId,
            6102
        );

        $second = $service->process(
            $documentId,
            6102
        );

        $this->assertSame(
            $first,
            $second
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_simulations61',
            1
        );
    }

    // ---------------------------------------------------------
    // 13 — PREVIEW FEATURE FLAG
    // ---------------------------------------------------------

    public function test_cod_fiscal61_disabled_preview_cannot_be_queued(): void
    {
        $fixture = $this->saleFixture61();

        Queue::fake();

        config()->set(
            'fiscal61.preview_enabled',
            false
        );

        $this->actor(6102);

        $this->expectException(
            DomainException::class
        );

        app(Fiscal61SimulationService::class)
            ->requestPreview(
                $fixture['document']['document_id']
            );
    }

    // ---------------------------------------------------------
    // 14 — INCIDENT AUDIT
    // ---------------------------------------------------------

    public function test_cod_fiscal61_incidents_have_signed_history(): void
    {
        $fixture = $this->saleFixture61();

        $id = $fixture['document']['document_id'];

        $this->actor(6104);

        $incident = app(
            Fiscal61IncidentService::class
        )->record(
            $id,
            'missing_tax_mapping',
            'Tax mapping requires administrator review'
        );

        $this->assertSame(
            $id,
            $incident['document_id']
        );

        $this->assertSame(
            'missing_tax_mapping',
            $incident['reason_code']
        );

        $this->actor(6103);

        $history = app(
            Fiscal61IncidentService::class
        )->history($id);

        $this->assertCount(1, $history);

        $this->assertSame(
            $incident,
            $history[0]
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_incidents61',
            1
        );
    }

    // ---------------------------------------------------------
    // 15 — CRM PAGINATION AND STATISTICS
    // ---------------------------------------------------------

    public function test_cod_fiscal61_crm_lists_sale_and_refund_drafts(): void
    {
        $fixture = $this->refundFixture61();

        $this->actor(6103);

        $service = app(
            Fiscal61ReportService::class
        );

        $first = $service->listDocuments(1);

        $this->assertCount(
            1,
            $first['items']
        );

        $this->assertSame(
            'refund',
            $first['items'][0]['kind']
        );

        $this->assertNotNull(
            $first['next_before_id']
        );

        $second = $service->listDocuments(
            1,
            $first['next_before_id']
        );

        $this->assertCount(
            1,
            $second['items']
        );

        $this->assertSame(
            'sale',
            $second['items'][0]['kind']
        );

        $stats = $service->statistics();

        $this->assertSame(
            1,
            $stats['sales_prepared']
        );

        $this->assertSame(
            1,
            $stats['refunds_prepared']
        );

        $this->assertSame(
            0,
            $stats['real_fiscal_receipts_issued']
        );
    }

    // ---------------------------------------------------------
    // 16 — API ROUTE PROTECTION
    // ---------------------------------------------------------

    public function test_cod_fiscal61_all_api_routes_require_sanctum(): void
    {
        foreach ([
                     'fiscal61.sale.prepare',
                     'fiscal61.refund.prepare',
                     'fiscal61.documents.index',
                     'fiscal61.documents.show',
                     'fiscal61.preview.queue',
                     'fiscal61.preview.show',
                     'fiscal61.incidents.store',
                     'fiscal61.incidents.index',
                     'fiscal61.statistics',
                 ] as $name) {
            $route = Route::getRoutes()
                ->getByName($name);

            $this->assertNotNull(
                $route,
                'Route missing: ' . $name
            );

            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware()
            );
        }
    }
}
