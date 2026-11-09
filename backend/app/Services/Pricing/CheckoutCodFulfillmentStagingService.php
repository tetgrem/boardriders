<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Audited internal custody: stage, independently review, add terminal hold. */
final class CheckoutCodFulfillmentStagingService
{
    public const STAGE_ABILITY = 'cod.fulfillment.staging.stage';
    public const REVIEW_ABILITY = 'cod.fulfillment.staging.review';
    public const HOLD_ABILITY = 'cod.fulfillment.staging.hold';

    public const HOLD_REASONS = [
        'carrier_document_missing',
        'package_integrity_doubt',
        'stock_hold_broken',
        'manual_security_hold',
        'reported_unknown_remote_outcome',
    ];

    public function __construct(
        private readonly CheckoutCodFulfillmentSourceService $sources
    ) {
    }

    /** @return array<string,mixed> */
    public function stage(
        string $orderKey,
        string $operationKey
    ): array {
        $actor = $this->actor(self::STAGE_ABILITY);
        $orderKey = $this->uuid($orderKey);
        $operationKey = $this->uuid($operationKey);

        return DB::transaction(function () use (
            $actor,
            $orderKey,
            $operationKey
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
                throw new DomainException('TTN intent not found');
            }

            $old = DB::table('checkout_cod_fulfillment_staging')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                $expected = $this->digest([
                    'cod-fulfillment-stage-v1',
                    $orderKey,
                    $operationKey,
                    $actor,
                    (int) $old->pack_check_id,
                    (int) $old->expected_units,
                    (string) $old->source_hmac,
                ]);

                if (
                    (int) $old->order_id !== (int) $order->id
                    || (int) $old->staged_by !== $actor
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $expected
                    )
                ) {
                    throw new DomainException(
                        'Staging UUID reused with different request'
                    );
                }

                return $this->presentStage($old);
            }

            if (
                DB::table('checkout_cod_fulfillment_staging')
                    ->where('order_id', $order->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Order already has immutable fulfillment staging'
                );
            }

            $src = $this->sources->inspect($orderKey);

            if (
                $src['pack_current'] !== true
                || (int) $src['intent_id'] !== (int) $intent->id
            ) {
                throw new DomainException(
                    'Reviewed active pack required before staging'
                );
            }

            $hash = $this->digest([
                'cod-fulfillment-stage-v1',
                $orderKey,
                $operationKey,
                $actor,
                $src['pack_check_id'],
                $src['expected_units'],
                $src['source_hmac'],
            ]);

            $id = DB::table('checkout_cod_fulfillment_staging')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'order_id' => $order->id,
                    'pack_check_id' => $src['pack_check_id'],
                    'source_hmac' => $src['source_hmac'],
                    'expected_units' => $src['expected_units'],
                    'staged_by' => $actor,
                    'request_hmac' => $hash,
                    'created_at' => now(),
                ]);

            return $this->presentStage(
                DB::table('checkout_cod_fulfillment_staging')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(
        string $stageKey,
        string $operationKey,
        string $decision,
        string $reason
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);
        $stageKey = $this->uuid($stageKey);
        $operationKey = $this->uuid($operationKey);

        $valid = (
                $decision === 'accepted'
                && $reason === 'internal_custody_verified'
            ) || (
                $decision === 'rejected'
                && in_array(
                    $reason,
                    [
                        'packaging_doubt',
                        'stock_discrepancy',
                        'security_concern',
                    ],
                    true
                )
            );

        if (!$valid) {
            throw new InvalidArgumentException(
                'Invalid staging review decision'
            );
        }

        return DB::transaction(function () use (
            $actor,
            $stageKey,
            $operationKey,
            $decision,
            $reason
        ): array {
            $stage = DB::table('checkout_cod_fulfillment_staging')
                ->where('operation_key', $stageKey)
                ->lockForUpdate()
                ->first();

            if ($stage === null) {
                throw new DomainException(
                    'Fulfillment staging not found'
                );
            }

            if ((int) $stage->staged_by === $actor) {
                throw new DomainException(
                    'Staging requires independent reviewer'
                );
            }

            $hash = $this->digest([
                'cod-fulfillment-review-v1',
                $stageKey,
                $operationKey,
                $actor,
                $decision,
                $reason,
                (string) $stage->source_hmac,
            ]);

            $old = DB::table('checkout_cod_fulfillment_staging_reviews')
                ->where('staging_id', $stage->id)
                ->first();

            if ($old !== null) {
                if (
                    (string) $old->operation_key !== $operationKey
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $hash
                    )
                ) {
                    throw new DomainException(
                        'Staging review already recorded'
                    );
                }

                return $this->presentReview($old);
            }

            if (
                DB::table('checkout_cod_fulfillment_staging_reviews')
                    ->where('operation_key', $operationKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Review UUID already used'
                );
            }

            if (
                DB::table('checkout_cod_fulfillment_holds')
                    ->where('staging_id', $stage->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Staging has a terminal safety hold'
                );
            }

            $orderKey = DB::table('orders')
                ->where('id', $stage->order_id)
                ->value('order_key');

            $src = $this->sources->inspect((string) $orderKey);

            if (
                $src['pack_current'] !== true
                || (int) $src['pack_check_id']
                !== (int) $stage->pack_check_id
                || !hash_equals(
                    (string) $src['source_hmac'],
                    (string) $stage->source_hmac
                )
            ) {
                throw new DomainException(
                    'Staging evidence is stale or held'
                );
            }

            $id = DB::table('checkout_cod_fulfillment_staging_reviews')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'staging_id' => $stage->id,
                    'decision' => $decision,
                    'reason_code' => $reason,
                    'reviewed_by' => $actor,
                    'request_hmac' => $hash,
                    'created_at' => now(),
                ]);

            return $this->presentReview(
                DB::table('checkout_cod_fulfillment_staging_reviews')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /**
     * A safety hold can be recorded after an independent review or expiry.
     * Holds are append-only and cannot be cleared by this module.
     *
     * @return array<string,mixed>
     */
    public function hold(
        string $stageKey,
        string $operationKey,
        string $reason
    ): array {
        $actor = $this->actor(self::HOLD_ABILITY);
        $stageKey = $this->uuid($stageKey);
        $operationKey = $this->uuid($operationKey);

        if (!in_array($reason, self::HOLD_REASONS, true)) {
            throw new InvalidArgumentException(
                'Invalid fulfillment hold reason'
            );
        }

        return DB::transaction(function () use (
            $actor,
            $stageKey,
            $operationKey,
            $reason
        ): array {
            $stage = DB::table('checkout_cod_fulfillment_staging')
                ->where('operation_key', $stageKey)
                ->lockForUpdate()
                ->first();

            if ($stage === null) {
                throw new DomainException(
                    'Fulfillment staging not found'
                );
            }

            $hash = $this->digest([
                'cod-fulfillment-hold-v1',
                $stageKey,
                $operationKey,
                $actor,
                $reason,
                (string) $stage->source_hmac,
            ]);

            $old = DB::table('checkout_cod_fulfillment_holds')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                if (
                    (int) $old->staging_id !== (int) $stage->id
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $hash
                    )
                ) {
                    throw new DomainException(
                        'Fulfillment hold UUID reused'
                    );
                }

                return $this->presentHold($old);
            }

            $id = DB::table('checkout_cod_fulfillment_holds')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'staging_id' => $stage->id,
                    'reason_code' => $reason,
                    'recorded_by' => $actor,
                    'request_hmac' => $hash,
                    'created_at' => now(),
                ]);

            return $this->presentHold(
                DB::table('checkout_cod_fulfillment_holds')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
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
                'COD fulfillment permission denied'
            );
        }

        return (int) $id;
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException(
                'Invalid fulfillment UUID'
            );
        }

        return strtolower($value);
    }

    private function digest(array $data): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY is missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string,mixed> */
    private function presentStage(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'order_id' => (int) $row->order_id,
            'pack_check_id' => (int) $row->pack_check_id,
            'staged_by' => (int) $row->staged_by,
            'inventory_shipped' => false,
            'carrier_handoff_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'staging_id' => (int) $row->staging_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'inventory_shipped' => false,
            'carrier_handoff_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentHold(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'staging_id' => (int) $row->staging_id,
            'reason_code' => (string) $row->reason_code,
            'recorded_by' => (int) $row->recorded_by,
            'carrier_handoff_allowed' => false,
        ];
    }
}
