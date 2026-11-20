<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Fiscal61IncidentService
{
    private const REASONS = [
        'missing_tax_mapping',
        'provider_not_configured',
        'original_receipt_missing',
        'amount_discrepancy',
        'manual_review',
        'operator_note',
    ];

    public function __construct(
        private readonly Fiscal61Authorization $authorization,
        private readonly Fiscal61AuditService $audit,
        private readonly CheckoutOrderReturnSigner $signer,
    ) {
    }

    public function record(
        int $documentId,
        string $reason,
        string $note
    ): array {
        $actor = $this->authorization->actor(
            Fiscal61Authorization::INCIDENT
        );

        $reason = trim($reason);
        $note = trim($note);

        if (
            !in_array($reason, self::REASONS, true)
            || mb_strlen($note) < 4
            || mb_strlen($note) > 500
        ) {
            throw new DomainException(
                'Invalid fiscal incident reason or note'
            );
        }

        return DB::transaction(function () use (
            $documentId,
            $reason,
            $note,
            $actor
        ): array {
            $document = $this->audit->read(
                $documentId
            );

            $identity = [
                'document_id' => $document['document_id'],
                'reason_code' => $reason,
                'note' => $note,
                'raised_by' => $actor,
            ];

            $id = (int) DB::table(
                'checkout_fiscal_incidents61'
            )->insertGetId([
                ...$identity,
                'incident_hmac' =>
                    $this->signer->digest($identity),
                'created_at' => now(),
            ]);

            return $this->read($id);
        }, 3);
    }

    public function history(int $documentId): array
    {
        $this->authorization->actor(
            Fiscal61Authorization::VIEW
        );

        $this->audit->read($documentId);

        $ids = DB::table(
            'checkout_fiscal_incidents61'
        )
            ->where('document_id', $documentId)
            ->orderBy('id')
            ->pluck('id');

        return $ids
            ->map(
                fn ($id): array =>
                $this->read((int) $id)
            )
            ->all();
    }

    private function read(int $incidentId): array
    {
        $row = DB::table(
            'checkout_fiscal_incidents61'
        )
            ->where('id', $incidentId)
            ->first();

        if ($row === null) {
            throw new DomainException(
                'Fiscal incident not found'
            );
        }

        $signed = [
            'document_id' => (int) $row->document_id,
            'reason_code' => (string) $row->reason_code,
            'note' => (string) $row->note,
            'raised_by' => (int) $row->raised_by,
        ];

        if (!hash_equals(
            (string) $row->incident_hmac,
            $this->signer->digest($signed)
        )) {
            throw new RuntimeException(
                'Fiscal incident audit signature mismatch'
            );
        }

        if (!in_array(
            $signed['reason_code'],
            self::REASONS,
            true
        )) {
            throw new RuntimeException(
                'Invalid stored fiscal incident'
            );
        }

        return [
            'incident_id' => $incidentId,
            ...$signed,
        ];
    }
}
