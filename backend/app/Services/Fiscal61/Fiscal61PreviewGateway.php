<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use DomainException;
use RuntimeException;

final class Fiscal61PreviewGateway
{
    /**
     * A deterministic, non-fiscal validation.
     *
     * This is not a production PRRO driver.
     * No network calls, signatures from a fiscal server,
     * receipt numbers or fabricated provider acknowledgments.
     */
    public function inspect(array $document): array
    {
        $payload = $document['payload'] ?? null;

        if (
            !is_array($payload)
            || !is_array($payload['items'] ?? null)
            || !array_is_list($payload['items'])
            || $payload['items'] === []
            || !in_array(
                $document['kind'] ?? null,
                ['sale', 'refund'],
                true
            )
        ) {
            throw new DomainException(
                'Invalid internal fiscal preview document'
            );
        }

        $sum = 0;
        $count = 0;

        foreach ($payload['items'] as $item) {
            if (
                !is_array($item)
                || !is_int($item['cash_minor'] ?? null)
                || $item['cash_minor'] < 0
                || $sum > PHP_INT_MAX - $item['cash_minor']
            ) {
                throw new RuntimeException(
                    'Fiscal preview item amount invalid'
                );
            }

            $sum += $item['cash_minor'];
            $count++;
        }

        if (
            $sum <= 0
            || $sum !== (int) $document['amount_minor']
            || $sum !== ($payload['amount_minor'] ?? null)
            || ($payload['currency_code'] ?? null) !== 'UAH'
            || ($payload['is_fiscal_receipt'] ?? null) !== false
        ) {
            throw new RuntimeException(
                'Fiscal preview source amount mismatch'
            );
        }

        $blockingReasons = [
            'fiscal_provider_not_connected',
            'tax_mapping_not_configured',
        ];

        if ($document['kind'] === 'refund') {
            $blockingReasons[] =
                'original_fiscal_receipt_not_confirmed';
        }

        return [
            'schema_version' => 1,
            'document_id' => (int) $document['document_id'],
            'kind' => (string) $document['kind'],
            'currency_code' => 'UAH',
            'amount_minor' => $sum,
            'item_count' => $count,
            'status' => 'preview_only',
            'source_verified' => true,
            'amount_balanced' => true,
            'tax_mapping_configured' => false,
            'provider_connected' => false,
            'ready_for_fiscal_submission' => false,
            'blocking_reasons' => $blockingReasons,
            'is_fiscal_receipt' => false,
            'fiscal_number' => null,
            'provider_submission_performed' => false,
        ];
    }
}
