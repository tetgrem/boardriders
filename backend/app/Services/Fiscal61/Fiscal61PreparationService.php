<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds immutable fiscal INTENTS, never fiscal receipts. */
final class Fiscal61PreparationService
{
    public function __construct(
        private readonly Fiscal61Authorization $auth,
        private readonly Fiscal61SourceService $sources,
        private readonly Fiscal61AuditService $audit,
        private readonly CheckoutOrderReturnSigner $signer,
    ) {
    }

    public function prepareSale(int $captureId): array
    {
        $actor = $this->auth->actor(
            Fiscal61Authorization::PREPARE
        );

        $this->enabled();

        if ($captureId <= 0) {
            throw new DomainException('Invalid capture ID');
        }

        return DB::transaction(function () use (
            $captureId,
            $actor
        ): array {
            $source = $this->sources->sale($captureId);

            DB::table('orders')
                ->where('id', $source['order_id'])
                ->lockForUpdate()
                ->first(['id']);

            return $this->store($source, null, $actor);
        }, 3);
    }

    public function prepareRefund(int $journalId): array
    {
        $actor = $this->auth->actor(
            Fiscal61Authorization::PREPARE
        );

        $this->enabled();

        if ($journalId <= 0) {
            throw new DomainException('Invalid journal ID');
        }

        return DB::transaction(function () use (
            $journalId,
            $actor
        ): array {
            $journal = DB::table(
                'checkout_mono_refund_journal56'
            )
                ->where('id', $journalId)
                ->first(['order_id', 'capture_receipt_id']);

            if ($journal === null) {
                throw new DomainException(
                    'Verified Mono journal required'
                );
            }

            DB::table('orders')
                ->where('id', $journal->order_id)
                ->lockForUpdate()
                ->first(['id']);

            $sale = DB::table('checkout_fiscal_documents61')
                ->where('kind', 'sale')
                ->where('source_id', $journal->capture_receipt_id)
                ->first();

            if ($sale === null) {
                throw new DomainException(
                    'Prepare original sale fiscal intent first'
                );
            }

            $saleRecord = $this->audit->read(
                (int) $sale->id
            );

            $saleSource = $this->sources->sale(
                (int) $journal->capture_receipt_id
            );

            if (
                $saleRecord['source_sha256']
                !== $saleSource['source_sha256']
            ) {
                throw new RuntimeException(
                    'Original paid sale source changed'
                );
            }

            $source = $this->sources->refund(
                $journalId,
                $saleSource,
                $saleRecord['payload']
            );

            return $this->store(
                $source,
                (int) $sale->id,
                $actor
            );
        }, 3);
    }

    private function store(
        array $source,
        ?int $saleId,
        int $actor
    ): array {
        $payloadJson = $this->signer->json(
            $source['payload']
        );

        $payloadHash = hash('sha256', $payloadJson);

        $existing = DB::table('checkout_fiscal_documents61')
            ->where('kind', $source['kind'])
            ->where('source_id', $source['source_id'])
            ->first();

        if ($existing !== null) {
            $saved = $this->audit->read(
                (int) $existing->id
            );

            if (
                $saved['order_id'] !== $source['order_id']
                || $saved['capture_receipt_id']
                !== $source['capture_receipt_id']
                || $saved['sale_document_id'] !== $saleId
                || $saved['amount_minor']
                !== $source['amount_minor']
                || $saved['source_sha256']
                !== $source['source_sha256']
                || $saved['payload_sha256'] !== $payloadHash
            ) {
                throw new DomainException(
                    'Fiscal intent source reused with different data'
                );
            }

            return $saved;
        }

        $signed = [
            'order_id' => $source['order_id'],
            'capture_receipt_id' =>
                $source['capture_receipt_id'],
            'sale_document_id' => $saleId,
            'kind' => $source['kind'],
            'source_id' => $source['source_id'],
            'amount_minor' => $source['amount_minor'],
            'currency_code' => 'UAH',
            'payload_sha256' => $payloadHash,
            'source_sha256' => $source['source_sha256'],
            'prepared_by' => $actor,
        ];

        $id = (int) DB::table(
            'checkout_fiscal_documents61'
        )->insertGetId([
            ...$signed,
            'payload_json' => $payloadJson,
            'document_hmac' =>
                $this->signer->digest($signed),
            'created_at' => now(),
        ]);

        return $this->audit->read($id);
    }

    private function enabled(): void
    {
        if (config('fiscal61.enabled') !== true) {
            throw new DomainException(
                'Fiscal61 preparation disabled'
            );
        }
    }
}
