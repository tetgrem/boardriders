<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Fiscal61AuditService
{
    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer,
    ) {
    }

    public function read(int $documentId): array
    {
        if ($documentId <= 0) {
            throw new DomainException(
                'Invalid fiscal document ID'
            );
        }

        $row = DB::table('checkout_fiscal_documents61')
            ->where('id', $documentId)
            ->first();

        if ($row === null) {
            throw new DomainException(
                'Fiscal draft document missing'
            );
        }

        $signed = [
            'order_id' => (int) $row->order_id,
            'capture_receipt_id' =>
                (int) $row->capture_receipt_id,
            'sale_document_id' =>
                $row->sale_document_id === null
                    ? null
                    : (int) $row->sale_document_id,
            'kind' => (string) $row->kind,
            'source_id' => (int) $row->source_id,
            'amount_minor' => (int) $row->amount_minor,
            'currency_code' => (string) $row->currency_code,
            'payload_sha256' =>
                (string) $row->payload_sha256,
            'source_sha256' =>
                (string) $row->source_sha256,
            'prepared_by' => (int) $row->prepared_by,
        ];

        if (!hash_equals(
            (string) $row->document_hmac,
            $this->signer->digest($signed)
        )) {
            throw new RuntimeException(
                'Fiscal document HMAC mismatch'
            );
        }

        $json = (string) $row->payload_json;

        if (!hash_equals(
            $signed['payload_sha256'],
            hash('sha256', $json)
        )) {
            throw new RuntimeException(
                'Fiscal payload SHA mismatch'
            );
        }

        $payload = json_decode(
            $json,
            true,
            64,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($payload)
            || ($payload['kind'] ?? null)
            !== $signed['kind']
            || ($payload['schema_version'] ?? null) !== 1
            || ($payload['amount_minor'] ?? null)
            !== $signed['amount_minor']
            || ($payload['currency_code'] ?? null) !== 'UAH'
            || ($payload['is_fiscal_receipt'] ?? null) !== false
            || !is_array($payload['items'] ?? null)
            || !array_is_list($payload['items'])
            || $payload['items'] === []
        ) {
            throw new RuntimeException(
                'Fiscal payload metadata mismatch'
            );
        }

        $total = 0;

        foreach ($payload['items'] as $item) {
            if (
                !is_array($item)
                || !is_int($item['cash_minor'] ?? null)
                || $item['cash_minor'] < 0
                || $total > PHP_INT_MAX - $item['cash_minor']
            ) {
                throw new RuntimeException(
                    'Fiscal payload item monetary mismatch'
                );
            }

            $total += $item['cash_minor'];
        }

        if ($total !== $signed['amount_minor']) {
            throw new RuntimeException(
                'Fiscal draft item totals mismatch'
            );
        }

        if ($signed['kind'] === 'sale') {
            if (
                $signed['sale_document_id'] !== null
                || $signed['source_id'] !==
                $signed['capture_receipt_id']
            ) {
                throw new RuntimeException(
                    'Sale draft source invalid'
                );
            }
        } elseif ($signed['kind'] === 'refund') {
            $parent = DB::table(
                'checkout_fiscal_documents61'
            )
                ->where('id', $signed['sale_document_id'])
                ->first();

            if (
                $parent === null
                || $parent->kind !== 'sale'
                || (int) $parent->order_id
                !== $signed['order_id']
                || (int) $parent->capture_receipt_id
                !== $signed['capture_receipt_id']
            ) {
                throw new RuntimeException(
                    'Refund has no matching original sale draft'
                );
            }

            // Parent integrity is checked too.
            $this->read((int) $parent->id);
        } else {
            throw new RuntimeException(
                'Unknown fiscal document type'
            );
        }

        return [
            'document_id' => $documentId,
            ...$signed,
            'payload' => $payload,
            'status' => 'prepared_non_fiscal',
            'is_fiscal_receipt' => false,
            'fiscal_number' => null,
            'provider_submission_performed' => false,
        ];
    }

    public function history(
        int $limit = 20,
        ?int $beforeId = null,
        ?string $kind = null
    ): array {
        if ($limit < 1 || $limit > 100) {
            throw new DomainException(
                'History limit must be 1..100'
            );
        }

        if ($beforeId !== null && $beforeId <= 0) {
            throw new DomainException(
                'Invalid history cursor'
            );
        }

        if (
            $kind !== null
            && !in_array($kind, ['sale', 'refund'], true)
        ) {
            throw new DomainException(
                'Invalid history document type'
            );
        }

        $query = DB::table(
            'checkout_fiscal_documents61'
        );

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        $ids = $query
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $hasNext = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);

        $entries = array_map(
            fn (int $id): array => $this->read($id),
            $ids
        );

        return [
            'items' => $entries,
            'next_before_id' =>
                $hasNext && $ids !== []
                    ? (int) end($ids)
                    : null,
        ];
    }
}
