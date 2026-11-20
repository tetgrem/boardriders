<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use Illuminate\Support\Facades\DB;

final class Fiscal61ReportService
{
    public function __construct(
        private readonly Fiscal61Authorization $authorization,
        private readonly Fiscal61AuditService $audit,
        private readonly Fiscal61SimulationService $simulations,
    ) {
    }

    public function document(int $documentId): array
    {
        $this->authorization->actor(
            Fiscal61Authorization::VIEW
        );

        $document = $this->audit->read($documentId);

        return [
            ...$document,
            'preview' => $this->simulations
                ->resultForDocument($documentId),
            'incident_count' => $this->incidentCount(
                $documentId
            ),
        ];
    }

    public function listDocuments(
        int $limit = 20,
        ?int $beforeId = null,
        ?string $kind = null
    ): array {
        $this->authorization->actor(
            Fiscal61Authorization::VIEW
        );

        $history = $this->audit->history(
            $limit,
            $beforeId,
            $kind
        );

        $items = [];

        foreach ($history['items'] as $document) {
            $id = (int) $document['document_id'];

            $preview = $this->simulations
                ->resultForDocument($id);

            $items[] = [
                'document_id' => $id,
                'order_id' => $document['order_id'],
                'capture_receipt_id' =>
                    $document['capture_receipt_id'],
                'sale_document_id' =>
                    $document['sale_document_id'],
                'kind' => $document['kind'],
                'amount_minor' => $document['amount_minor'],
                'currency_code' => 'UAH',
                'status' => $preview === null
                    ? 'prepared_non_fiscal'
                    : 'preview_only',
                'preview_processed' => $preview !== null,
                'incident_count' => $this->incidentCount($id),
                'is_fiscal_receipt' => false,
                'fiscal_number' => null,
            ];
        }

        return [
            'items' => $items,
            'next_before_id' =>
                $history['next_before_id'],
        ];
    }

    public function statistics(): array
    {
        $this->authorization->actor(
            Fiscal61Authorization::VIEW
        );

        $sales = DB::table(
            'checkout_fiscal_documents61'
        )
            ->where('kind', 'sale')
            ->count();

        $refunds = DB::table(
            'checkout_fiscal_documents61'
        )
            ->where('kind', 'refund')
            ->count();

        $saleMinor = (int) DB::table(
            'checkout_fiscal_documents61'
        )
            ->where('kind', 'sale')
            ->sum('amount_minor');

        $refundMinor = (int) DB::table(
            'checkout_fiscal_documents61'
        )
            ->where('kind', 'refund')
            ->sum('amount_minor');

        $previews = DB::table(
            'checkout_fiscal_simulations61'
        )->count();

        $incidents = DB::table(
            'checkout_fiscal_incidents61'
        )->count();

        $affectedDocuments = DB::table(
            'checkout_fiscal_incidents61'
        )
            ->distinct()
            ->count('document_id');

        return [
            'sales_prepared' => (int) $sales,
            'refunds_prepared' => (int) $refunds,
            'sale_drafts_minor' => $saleMinor,
            'refund_drafts_minor' => $refundMinor,
            'previews_processed' => (int) $previews,
            'incidents' => (int) $incidents,
            'documents_with_incidents' =>
                (int) $affectedDocuments,
            'currency_code' => 'UAH',
            'real_fiscal_receipts_issued' => 0,
            'provider_connected' => false,
            'statistics_are_internal_drafts' => true,
        ];
    }

    private function incidentCount(int $documentId): int
    {
        return (int) DB::table(
            'checkout_fiscal_incidents61'
        )
            ->where('document_id', $documentId)
            ->count();
    }
}
