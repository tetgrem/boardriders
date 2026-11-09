<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Two-person, immutable warehouse verification. Not shipping authorization. */
final class CheckoutCodWarehousePackService
{
    public const CHECK_ABILITY = 'nova-poshta.warehouse-pack.check';
    public const REVIEW_ABILITY = 'nova-poshta.warehouse-pack.review';

    public function __construct(
        private readonly CheckoutCodWarehousePackReadinessService $readiness
    ) {
    }

    /** @return array<string,mixed> */
    public function check(
        string $orderKey,
        string $operationKey,
        string $containerType,
        int $countedUnits,
        int $grossGrams,
        int $lengthMm,
        int $widthMm,
        int $heightMm,
    ): array {
        $actor = $this->actor(self::CHECK_ABILITY);
        $orderKey = $this->uuid($orderKey);
        $operationKey = $this->uuid($operationKey);

        if (
            !in_array($containerType, ['mailer', 'box', 'reinforced_box'], true)
            || $countedUnits < 1
            || $countedUnits > 19800
            || $grossGrams < 1
            || $grossGrams > 10000000
            || min($lengthMm, $widthMm, $heightMm) < 1
            || max($lengthMm, $widthMm, $heightMm) > 100000
        ) {
            throw new InvalidArgumentException(
                'Invalid physical pack measurements'
            );
        }

        return DB::transaction(function () use (
            $actor,
            $orderKey,
            $operationKey,
            $containerType,
            $countedUnits,
            $grossGrams,
            $lengthMm,
            $widthMm,
            $heightMm
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->first(['id']);

            if ($order === null) {
                throw new DomainException('Warehouse order not found');
            }

            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first(['id']);

            if ($intent === null) {
                throw new DomainException(
                    'Warehouse plan requires TTN intent'
                );
            }

            $existing = DB::table('checkout_cod_warehouse_pack_checks')
                ->where('operation_key', $operationKey)
                ->first();

            if ($existing !== null) {
                $expected = $this->digest([
                    'cod-pack-check-v1',
                    $orderKey,
                    $operationKey,
                    $actor,
                    $containerType,
                    $countedUnits,
                    $grossGrams,
                    $lengthMm,
                    $widthMm,
                    $heightMm,
                    (string) $existing->source_hmac,
                ]);

                if (
                    (int) $existing->order_id !== (int) $order->id
                    || (int) $existing->checked_by !== $actor
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $expected
                    )
                ) {
                    throw new DomainException(
                        'Pack operation UUID reused with changed request'
                    );
                }

                return $this->presentCheck($existing);
            }

            if (
                DB::table('checkout_cod_warehouse_pack_checks')
                    ->where('order_id', $order->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Immutable pack check already exists for order'
                );
            }

            $source = $this->readiness->inspect($orderKey);

            if (
                $source['source_ready'] !== true
                || (int) $source['intent_id'] !== (int) $intent->id
                || $source['total_units'] !== $countedUnits
                || $source['gross_grams'] !== $grossGrams
                || $source['length_mm'] !== $lengthMm
                || $source['width_mm'] !== $widthMm
                || $source['height_mm'] !== $heightMm
            ) {
                throw new DomainException(
                    'Physical packing differs from approved COD source'
                );
            }

            $hmac = $this->digest([
                'cod-pack-check-v1',
                $orderKey,
                $operationKey,
                $actor,
                $containerType,
                $countedUnits,
                $grossGrams,
                $lengthMm,
                $widthMm,
                $heightMm,
                $source['source_hmac'],
            ]);

            $id = DB::table('checkout_cod_warehouse_pack_checks')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'order_id' => $order->id,
                    'dispatch_plan_id' => $source['plan_id'],
                    'manifest_id' => $source['manifest_id'],
                    'assessment_id' => $source['assessment_id'],
                    'source_hmac' => $source['source_hmac'],
                    'items_sha256' => $source['items_sha256'],
                    'container_type' => $containerType,
                    'counted_units' => $countedUnits,
                    'gross_grams' => $grossGrams,
                    'length_mm' => $lengthMm,
                    'width_mm' => $widthMm,
                    'height_mm' => $heightMm,
                    'checked_by' => $actor,
                    'request_hmac' => $hmac,
                    'created_at' => now(),
                ]);

            return $this->presentCheck(
                DB::table('checkout_cod_warehouse_pack_checks')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(
        string $checkKey,
        string $operationKey,
        string $decision,
        string $reason
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);
        $checkKey = $this->uuid($checkKey);
        $operationKey = $this->uuid($operationKey);

        if (!(
            (
                $decision === 'accepted'
                && $reason === 'physical_count_and_dimensions_verified'
            )
            || (
                $decision === 'rejected'
                && in_array(
                    $reason,
                    ['physical_mismatch', 'packaging_damaged'],
                    true
                )
            )
        )) {
            throw new InvalidArgumentException(
                'Invalid pack review decision'
            );
        }

        return DB::transaction(function () use (
            $actor,
            $checkKey,
            $operationKey,
            $decision,
            $reason
        ): array {
            $check = DB::table('checkout_cod_warehouse_pack_checks')
                ->where('operation_key', $checkKey)
                ->lockForUpdate()
                ->first();

            if ($check === null) {
                throw new DomainException('Pack check not found');
            }

            if ((int) $check->checked_by === $actor) {
                throw new DomainException(
                    'Pack checker cannot approve own pack'
                );
            }

            $requestHmac = $this->digest([
                'cod-pack-review-v1',
                $checkKey,
                $operationKey,
                $actor,
                $decision,
                $reason,
                (string) $check->source_hmac,
            ]);

            $previous = DB::table('checkout_cod_warehouse_pack_reviews')
                ->where('pack_check_id', $check->id)
                ->first();

            if ($previous !== null) {
                if (
                    (string) $previous->operation_key !== $operationKey
                    || !hash_equals(
                        (string) $previous->request_hmac,
                        $requestHmac
                    )
                ) {
                    throw new DomainException(
                        'Pack check was already independently reviewed'
                    );
                }

                return $this->presentReview($previous);
            }

            if (
                DB::table('checkout_cod_warehouse_pack_reviews')
                    ->where('operation_key', $operationKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Pack review UUID already belongs to another check'
                );
            }

            $orderKey = DB::table('orders')
                ->where('id', $check->order_id)
                ->value('order_key');

            $source = $this->readiness->inspect((string) $orderKey);

            if (
                $source['source_ready'] !== true
                || (int) $source['plan_id'] !== (int) $check->dispatch_plan_id
                || !hash_equals(
                    (string) $source['source_hmac'],
                    (string) $check->source_hmac
                )
            ) {
                throw new DomainException(
                    'Pack source has changed or is blocked'
                );
            }

            $id = DB::table('checkout_cod_warehouse_pack_reviews')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'pack_check_id' => $check->id,
                    'decision' => $decision,
                    'reason_code' => $reason,
                    'reviewed_by' => $actor,
                    'request_hmac' => $requestHmac,
                    'created_at' => now(),
                ]);

            return $this->presentReview(
                DB::table('checkout_cod_warehouse_pack_reviews')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    private function uuid(string $uuid): string
    {
        if (!Str::isUuid($uuid)) {
            throw new InvalidArgumentException(
                'Invalid warehouse operation UUID'
            );
        }

        return strtolower($uuid);
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
                'Warehouse operation permission denied'
            );
        }

        return (int) $id;
    }

    private function digest(array $data): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new DomainException('Missing APP_KEY');
        }

        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $secret
        );
    }

    private function presentCheck(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'order_id' => (int) $row->order_id,
            'container_type' => (string) $row->container_type,
            'counted_units' => (int) $row->counted_units,
            'checked_by' => (int) $row->checked_by,
            'carrier_label_available' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }

    private function presentReview(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'pack_check_id' => (int) $row->pack_check_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'carrier_label_available' => false,
            'ttn_submission_allowed' => false,
        ];
    }
}
