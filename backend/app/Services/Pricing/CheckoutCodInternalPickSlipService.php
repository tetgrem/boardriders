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
 * Audits requests for printable INTERNAL pick slips;
 * never prints a carrier label.
 */
final class CheckoutCodInternalPickSlipService
{
    public const GENERATE_ABILITY =
        'nova-poshta.warehouse-pick-slip.generate';

    public function __construct(
        private readonly CheckoutCodWarehousePackReadinessService $readiness,
        private readonly CheckoutCodInternalPickSlipFormatter $formatter,
    ) {
    }

    /** @return array<string,mixed> */
    public function generate(
        string $checkOperationKey,
        string $printOperationKey
    ): array {
        $actor = $this->actor();

        if (
            !Str::isUuid($checkOperationKey)
            || !Str::isUuid($printOperationKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid warehouse slip UUID'
            );
        }

        $checkOperationKey = strtolower($checkOperationKey);
        $printOperationKey = strtolower($printOperationKey);

        return DB::transaction(function () use (
            $actor,
            $checkOperationKey,
            $printOperationKey
        ): array {
            $check = DB::table('checkout_cod_warehouse_pack_checks')
                ->where('operation_key', $checkOperationKey)
                ->first();

            if ($check === null) {
                throw new DomainException(
                    'Warehouse pack check not found'
                );
            }

            $plan = DB::table('checkout_cod_ttn_dispatch_plans')
                ->where('id', $check->dispatch_plan_id)
                ->first(['intent_id']);

            if ($plan === null) {
                throw new DomainException('Dispatch plan missing');
            }

            DB::table('checkout_cod_ttn_submission_intents')
                ->where('id', $plan->intent_id)
                ->lockForUpdate()
                ->first(['id']);

            $review = DB::table('checkout_cod_warehouse_pack_reviews')
                ->where('pack_check_id', $check->id)
                ->first(['decision']);

            if ($review?->decision !== 'accepted') {
                throw new DomainException(
                    'Independent pack acceptance required'
                );
            }

            $orderKey = DB::table('orders')
                ->where('id', $check->order_id)
                ->value('order_key');

            $source = $this->readiness->inspect((string) $orderKey);

            if (
                $source['source_ready'] !== true
                || (int) $source['plan_id']
                !== (int) $check->dispatch_plan_id
                || !hash_equals(
                    (string) $source['source_hmac'],
                    (string) $check->source_hmac
                )
            ) {
                throw new DomainException(
                    'Internal pick slip is stale or blocked'
                );
            }

            $hash = $this->digest([
                'cod-internal-pick-slip-v1',
                $checkOperationKey,
                $printOperationKey,
                $actor,
                (string) $check->source_hmac,
            ]);

            $previous = DB::table('checkout_cod_warehouse_pick_slip_events')
                ->where('operation_key', $printOperationKey)
                ->first();

            if ($previous !== null) {
                if (
                    (int) $previous->pack_check_id !== (int) $check->id
                    || (int) $previous->requested_by !== $actor
                    || !hash_equals(
                        (string) $previous->request_hmac,
                        $hash
                    )
                ) {
                    throw new DomainException(
                        'Pick slip UUID reused for different request'
                    );
                }

                $event = $previous;
            } else {
                $id = DB::table('checkout_cod_warehouse_pick_slip_events')
                    ->insertGetId([
                        'operation_key' => $printOperationKey,
                        'pack_check_id' => $check->id,
                        'requested_by' => $actor,
                        'request_hmac' => $hash,
                        'created_at' => now(),
                    ]);

                $event = DB::table('checkout_cod_warehouse_pick_slip_events')
                    ->where('id', $id)
                    ->first();
            }

            $text = $this->formatter->render([
                'order_key' => (string) $orderKey,
                'container_type' => (string) $check->container_type,
                'total_units' => $source['total_units'],
                'gross_grams' => $source['gross_grams'],
                'length_mm' => $source['length_mm'],
                'width_mm' => $source['width_mm'],
                'height_mm' => $source['height_mm'],
                'items' => $source['items'],
            ]);

            return [
                'id' => (int) $event->id,
                'operation_key' => (string) $event->operation_key,
                'pack_check_id' => (int) $check->id,
                'content' => $text,
                'document_kind' => 'internal_pick_slip',
                'physical_print_confirmed' => false,
                'carrier_label' => false,
                'ttn_submission_allowed' => false,
            ];
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(self::GENERATE_ABILITY)
        ) {
            throw new AuthorizationException(
                'Pick slip generation permission denied'
            );
        }

        return (int) $id;
    }

    private function digest(array $payload): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new DomainException('Missing APP_KEY');
        }

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $secret
        );
    }
}
