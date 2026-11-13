<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/** Authorized canonical CSV ingestion into the existing unverified Block 39 register. */
final class CheckoutCodNovaPayRegisterCsvImportService
{
    public function __construct(
        private readonly CheckoutCodNovaPayRegisterCsvParser $parser,
        private readonly CheckoutCodNovaPayRegisterService $captures
    ) {
    }

    /** @return array<string,mixed> */
    public function import(string $postingKey, string $operationKey, string $rawCsv): array
    {
        // Do not parse untrusted file bytes before authorization.
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(CheckoutCodNovaPayRegisterService::CAPTURE_ABILITY)) {
            throw new AuthorizationException('NovaPay CSV import permission denied');
        }

        $parsed = $this->parser->parse($rawCsv);

        $result = $this->captures->capture(
            $postingKey,
            $operationKey,
            $parsed['document_sha256'],
            $parsed['rows']
        );

        return array_merge($result, [
            'input_format' => 'boardriders_canonical_csv_v1',
            'document_sha256' => $parsed['document_sha256'],
            'imported_rows_count' => $parsed['row_count'],
            'novapay_origin_authenticated' => false,
            'customer_payment_confirmed' => false,
            'orders_payment_status_changed' => false,
            'cod_paid' => false,
        ]);
    }
}
