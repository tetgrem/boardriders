<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only two-person resolution documentation.
 * NEVER a provider submission permit.
 */
final class CheckoutCodTtnResolutionService
{
    public const CAPTURE_ABILITY =
        'nova-poshta.ttn-resolution.capture';

    public const REVIEW_ABILITY =
        'nova-poshta.ttn-resolution.review';

    public function __construct(
        private readonly CheckoutCodTtnEvidenceMatrixService $matrix
    ) {
    }

    /** @return array<string,mixed> */
    public function capture(
        string $orderKey,
        string $operationKey
    ): array {
        $actor = $this->actor(self::CAPTURE_ABILITY);
        $orderKey = $this->uuid($orderKey);
        $operationKey = $this->uuid($operationKey);

        return DB::transaction(function () use (
            $orderKey,
            $operationKey,
            $actor
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->first(['id']);

            if ($order === null) {
                throw new DomainException('COD order not found');
            }

            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first(['id']);

            if ($intent === null) {
                throw new DomainException('COD TTN intent not found');
            }

            $previous = DB::table('checkout_cod_ttn_resolution_snapshots')
                ->where('operation_key', $operationKey)
                ->first();

            if ($previous !== null) {
                $expected = $this->digest([
                    'cod-ttn-resolution-capture-v1',
                    $orderKey,
                    $operationKey,
                    $actor,
                    (string) $previous->evidence_hmac,
                ]);

                if (
                    (int) $previous->intent_id !== (int) $intent->id
                    || (int) $previous->created_by !== $actor
                    || !hash_equals(
                        (string) $previous->request_hmac,
                        $expected
                    )
                ) {
                    throw new DomainException(
                        'Resolution operation UUID reused with changed data'
                    );
                }

                return $this->presentSnapshot($previous);
            }

            $report = $this->matrix->forIntent((int) $intent->id);

            if (
                DB::table('checkout_cod_ttn_resolution_snapshots')
                    ->where('intent_id', $intent->id)
                    ->where('evidence_hmac', $report['evidence_hmac'])
                    ->exists()
            ) {
                throw new DomainException(
                    'Evidence state already captured with another operation UUID'
                );
            }

            $id = DB::table('checkout_cod_ttn_resolution_snapshots')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'intent_id' => $intent->id,
                    'trigger_event_id' => $report['trigger_event_id'],
                    'latest_scan_id' => $report['latest_scan_id'],
                    'context_hmac' => $report['context_hmac'],
                    'evidence_hmac' => $report['evidence_hmac'],
                    'outcome' => $report['outcome'],
                    'created_by' => $actor,
                    'request_hmac' => $this->digest([
                        'cod-ttn-resolution-capture-v1',
                        $orderKey,
                        $operationKey,
                        $actor,
                        $report['evidence_hmac'],
                    ]),
                    'created_at' => now(),
                ]);

            return $this->presentSnapshot(
                DB::table('checkout_cod_ttn_resolution_snapshots')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(
        string $snapshotOperationKey,
        string $reviewKey,
        string $decision,
        string $reason
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);
        $snapshotOperationKey = $this->uuid($snapshotOperationKey);
        $reviewKey = $this->uuid($reviewKey);

        $valid = (
                $decision === 'retain_hold'
                && $reason === 'evidence_not_authoritative'
            ) || (
                $decision === 'escalate_to_carrier'
                && $reason === 'carrier_confirmation_required'
            );

        if (!$valid) {
            throw new InvalidArgumentException(
                'Invalid COD TTN resolution decision'
            );
        }

        return DB::transaction(function () use (
            $actor,
            $snapshotOperationKey,
            $reviewKey,
            $decision,
            $reason
        ): array {
            $snapshot = DB::table('checkout_cod_ttn_resolution_snapshots')
                ->where('operation_key', $snapshotOperationKey)
                ->lockForUpdate()
                ->first();

            if ($snapshot === null) {
                throw new DomainException(
                    'Resolution snapshot not found'
                );
            }

            if ((int) $snapshot->created_by === $actor) {
                throw new DomainException(
                    'Snapshot author cannot review own evidence'
                );
            }

            $hash = $this->digest([
                'cod-ttn-resolution-review-v1',
                $snapshotOperationKey,
                $reviewKey,
                $actor,
                $decision,
                $reason,
                (string) $snapshot->evidence_hmac,
            ]);

            $previous = DB::table('checkout_cod_ttn_resolution_reviews')
                ->where('snapshot_id', $snapshot->id)
                ->first();

            if ($previous !== null) {
                if (
                    (string) $previous->operation_key !== $reviewKey
                    || !hash_equals(
                        (string) $previous->request_hmac,
                        $hash
                    )
                ) {
                    throw new DomainException(
                        'Resolution snapshot already independently reviewed'
                    );
                }

                return $this->presentReview($previous);
            }

            if (
                DB::table('checkout_cod_ttn_resolution_reviews')
                    ->where('operation_key', $reviewKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Resolution review UUID already used'
                );
            }

            $report = $this->matrix->forIntent(
                (int) $snapshot->intent_id
            );

            if (
                (int) $report['trigger_event_id']
                !== (int) $snapshot->trigger_event_id
                || !hash_equals(
                    (string) $snapshot->evidence_hmac,
                    (string) $report['evidence_hmac']
                )
            ) {
                throw new DomainException(
                    'Evidence changed; capture a new resolution snapshot'
                );
            }

            $id = DB::table('checkout_cod_ttn_resolution_reviews')
                ->insertGetId([
                    'operation_key' => $reviewKey,
                    'snapshot_id' => $snapshot->id,
                    'decision' => $decision,
                    'reason_code' => $reason,
                    'reviewed_by' => $actor,
                    'request_hmac' => $hash,
                    'created_at' => now(),
                ]);

            return $this->presentReview(
                DB::table('checkout_cod_ttn_resolution_reviews')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException(
                'Invalid COD TTN resolution UUID'
            );
        }

        return strtolower($value);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException(
                'COD TTN resolution permission denied'
            );
        }

        return (int) $id;
    }

    private function digest(array $payload): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('Application key is missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string,mixed> */
    private function presentSnapshot(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'intent_id' => (int) $row->intent_id,
            'outcome' => (string) $row->outcome,
            'created_by' => (int) $row->created_by,
            'reconciliation_hold_released' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'snapshot_id' => (int) $row->snapshot_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'reconciliation_hold_released' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
        ];
    }
}
