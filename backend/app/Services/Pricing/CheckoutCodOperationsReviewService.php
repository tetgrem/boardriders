<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only operational reviews.
 * Acknowledgements cannot unlock payments.
 */
final class CheckoutCodOperationsReviewService
{
    public const REVIEW_ABILITY = 'cod.operations.review';

    private const ALLOWED = [
        'request_evidence' => [
            'source_proof_needed',
            'shipment_proof_needed',
            'manual_recheck',
        ],
        'escalate_investigation' => [
            'bank_difference',
            'financial_hold',
            'reversal',
            'suspicious_claim',
            'manual_recheck',
        ],
        'acknowledge_comparison' => [
            'allocation_checked',
        ],
    ];

    public function __construct(
        private readonly CheckoutCodOperationsService $operations
    ) {
    }

    /** @return array<string,mixed> */
    public function record(
        string $postingKey,
        string $operationKey,
        string $expectedSnapshotHmac,
        string $action,
        string $reasonCode
    ): array {
        $actor = $this->authorize();

        if (
            !Str::isUuid($postingKey)
            || !Str::isUuid($operationKey)
            || !preg_match(
                '/^[a-f0-9]{64}$/D',
                $expectedSnapshotHmac
            )
            || !isset(self::ALLOWED[$action])
            || !in_array(
                $reasonCode,
                self::ALLOWED[$action],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid COD review request'
            );
        }

        $postingKey = strtolower($postingKey);
        $operationKey = strtolower($operationKey);

        $requestHmac = $this->digest([
            'cod-operations-review-request-v41',
            $postingKey,
            $operationKey,
            $expectedSnapshotHmac,
            $action,
            $reasonCode,
            $actor,
        ]);

        return DB::transaction(function () use (
            $postingKey,
            $operationKey,
            $expectedSnapshotHmac,
            $action,
            $reasonCode,
            $requestHmac,
            $actor
        ): array {
            $posting = DB::table(
                'checkout_cod_bank_receipt_postings'
            )
                ->where('operation_key', $postingKey)
                ->lockForUpdate()
                ->first();

            if ($posting === null) {
                throw new DomainException(
                    'COD bank posting not found'
                );
            }

            $old = DB::table('checkout_cod_operations_reviews')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                if (
                    (int) $old->bank_posting_id
                    !== (int) $posting->id
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $requestHmac
                    )
                ) {
                    throw new DomainException(
                        'COD review UUID reused with different request'
                    );
                }

                return $this->present($old, true);
            }

            $capture = DB::table(
                'checkout_cod_novapay_register_captures'
            )
                ->where('bank_posting_id', $posting->id)
                ->first();

            if (
                $actor === (int) $posting->posted_by
                || (
                    $capture !== null
                    && $actor === (int) $capture->captured_by
                )
            ) {
                throw new DomainException(
                    'Independent COD reviewer required'
                );
            }

            // Evaluate current facts under the posting lock.
            $state = $this->operations->inspect($postingKey);

            if (!hash_equals(
                $expectedSnapshotHmac,
                $state['snapshot_hmac']
            )) {
                throw new DomainException(
                    'COD case changed; inspect it again'
                );
            }

            if (
                $action === 'acknowledge_comparison'
                && (
                    $state['status'] !== 'awaiting_external_proof'
                    || $state['capture_id'] === null
                    || $state['priority'] !== 'low'
                )
            ) {
                throw new DomainException(
                    'Only clean comparisons can be acknowledged'
                );
            }

            $id = DB::table(
                'checkout_cod_operations_reviews'
            )->insertGetId([
                'operation_key' => $operationKey,
                'bank_posting_id' => (int) $posting->id,
                'review_action' => $action,
                'reason_code' => $reasonCode,
                'snapshot_hmac' => $expectedSnapshotHmac,
                'reviewed_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);

            $saved = DB::table(
                'checkout_cod_operations_reviews'
            )
                ->where('id', $id)
                ->first();

            return $this->present($saved, false);
        }, 3);
    }

    /** @return array<string,mixed> */
    private function present(object $r, bool $replay): array
    {
        return [
            'review_id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'bank_posting_id' => (int) $r->bank_posting_id,
            'review_action' => (string) $r->review_action,
            'reason_code' => (string) $r->reason_code,
            'reviewed_by' => (int) $r->reviewed_by,
            'idempotent_replay' => $replay,
            'order_payment_status_changed' => false,
            'automatic_payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    private function digest(array $parts): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($parts, JSON_THROW_ON_ERROR),
            $key
        );
    }

    private function authorize(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (
                !is_int($id)
                && !(is_string($id) && ctype_digit($id))
            )
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                self::REVIEW_ABILITY
            )
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'COD operations review denied'
            );
        }

        return (int) $id;
    }
}
