<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use App\Jobs\Fiscal61PreviewJob;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Fiscal61SimulationService
{
    public function __construct(
        private readonly Fiscal61Authorization $authorization,
        private readonly Fiscal61AuditService $audit,
        private readonly Fiscal61PreviewGateway $gateway,
        private readonly CheckoutOrderReturnSigner $signer,
    ) {
    }

    /**
     * API entry point: authenticated employee requests a preview.
     */
    public function requestPreview(int $documentId): array
    {
        $actor = $this->authorization->actor(
            Fiscal61Authorization::SIMULATE
        );

        $this->enabled();

        $document = $this->audit->read($documentId);

        $existing = $this->resultForDocument($documentId);

        if ($existing !== null) {
            return $existing;
        }

        Fiscal61PreviewJob::dispatch(
            $documentId,
            $actor
        )
            ->onQueue('fiscal-preview61')
            ->afterCommit();

        return [
            'document_id' => $documentId,
            'status' => 'queued_preview',
            'is_fiscal_receipt' => false,
            'fiscal_number' => null,
            'provider_submission_performed' => false,
        ];
    }

    /**
     * Internal queue worker entry point.
     *
     * Never expose process() directly as an HTTP action.
     */
    public function process(
        int $documentId,
        int $requestedBy
    ): array {
        $this->enabled();

        if ($documentId <= 0 || $requestedBy <= 0) {
            throw new DomainException(
                'Invalid fiscal preview job identity'
            );
        }

        return DB::transaction(function () use (
            $documentId,
            $requestedBy
        ): array {
            $locator = DB::table(
                'checkout_fiscal_documents61'
            )
                ->where('id', $documentId)
                ->first(['order_id']);

            if ($locator === null) {
                throw new DomainException(
                    'Fiscal preview source missing'
                );
            }

            // Serialize jobs against order processing.
            $order = DB::table('orders')
                ->where('id', $locator->order_id)
                ->lockForUpdate()
                ->first(['id']);

            if ($order === null) {
                throw new DomainException(
                    'Fiscal preview original order missing'
                );
            }

            $document = $this->audit->read(
                $documentId
            );

            $existing = $this->resultForDocument(
                $documentId
            );

            if ($existing !== null) {
                return $existing;
            }

            $output = $this->gateway->inspect(
                $document
            );

            if (
                $output['is_fiscal_receipt'] !== false
                || $output['provider_submission_performed'] !== false
                || $output['fiscal_number'] !== null
            ) {
                throw new RuntimeException(
                    'Preview must never issue a fiscal receipt'
                );
            }

            $json = $this->signer->json($output);
            $hash = hash('sha256', $json);

            $signed = [
                'document_id' => $documentId,
                'input_sha256' =>
                    $document['payload_sha256'],
                'output_sha256' => $hash,
                'simulated_by' => $requestedBy,
            ];

            DB::table(
                'checkout_fiscal_simulations61'
            )->insert([
                ...$signed,
                'output_json' => $json,
                'simulation_hmac' =>
                    $this->signer->digest($signed),
                'created_at' => now(),
            ]);

            return $this->resultForDocument(
                $documentId
            );
        }, 3);
    }

    public function show(int $documentId): array
    {
        $this->authorization->actor(
            Fiscal61Authorization::VIEW
        );

        $result = $this->resultForDocument(
            $documentId
        );

        if ($result === null) {
            throw new DomainException(
                'Fiscal preview not processed yet'
            );
        }

        return $result;
    }

    /**
     * Internal read method for the CRM/report layer.
     * Public HTTP routes must call show() instead.
     */
    public function resultForDocument(
        int $documentId
    ): ?array {
        $document = $this->audit->read(
            $documentId
        );

        $row = DB::table(
            'checkout_fiscal_simulations61'
        )
            ->where('document_id', $documentId)
            ->first();

        if ($row === null) {
            return null;
        }

        $signed = [
            'document_id' => (int) $row->document_id,
            'input_sha256' =>
                (string) $row->input_sha256,
            'output_sha256' =>
                (string) $row->output_sha256,
            'simulated_by' =>
                (int) $row->simulated_by,
        ];

        if (
            !hash_equals(
                (string) $row->simulation_hmac,
                $this->signer->digest($signed)
            )
            || !hash_equals(
                $document['payload_sha256'],
                $signed['input_sha256']
            )
        ) {
            throw new RuntimeException(
                'Fiscal preview audit signature mismatch'
            );
        }

        $json = (string) $row->output_json;

        if (!hash_equals(
            hash('sha256', $json),
            $signed['output_sha256']
        )) {
            throw new RuntimeException(
                'Fiscal preview result checksum mismatch'
            );
        }

        $output = json_decode(
            $json,
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($output)
            || ($output['status'] ?? null) !== 'preview_only'
            || ($output['document_id'] ?? null)
            !== $documentId
            || ($output['amount_minor'] ?? null)
            !== $document['amount_minor']
            || ($output['kind'] ?? null)
            !== $document['kind']
            || ($output['is_fiscal_receipt'] ?? null)
            !== false
            || ($output['provider_submission_performed'] ?? null)
            !== false
            || !array_key_exists('fiscal_number', $output)
            || $output['fiscal_number'] !== null
            || ($output['ready_for_fiscal_submission'] ?? null)
            !== false
        ) {
            throw new RuntimeException(
                'Fiscal preview contains invalid provider status'
            );
        }

        return [
            'document_id' => $documentId,
            'status' => 'preview_only',
            'simulated_by' => $signed['simulated_by'],
            'preview' => $output,
            'is_fiscal_receipt' => false,
            'fiscal_number' => null,
            'provider_submission_performed' => false,
        ];
    }

    private function enabled(): void
    {
        if (
            config('fiscal61.enabled') !== true
            || config('fiscal61.preview_enabled') !== true
        ) {
            throw new DomainException(
                'Fiscal61 preview is disabled'
            );
        }
    }
}
