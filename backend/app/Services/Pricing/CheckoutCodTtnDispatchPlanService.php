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
 * Immutable internal preparation & independent review.
 * Not a submission attempt.
 */
final class CheckoutCodTtnDispatchPlanService
{
    public const PREPARE_ABILITY = 'nova-poshta.ttn-dispatch.prepare';
    public const REVIEW_ABILITY = 'nova-poshta.ttn-dispatch.review';

    public function __construct(
        private readonly CheckoutCodTtnDispatchReadinessService $readiness
    ) {
    }

    /** @return array<string,mixed> */
    public function prepare(
        string $orderKey,
        string $operationKey
    ): array {
        $actor = $this->actor(self::PREPARE_ABILITY);
        $orderKey = $this->uuid($orderKey);
        $operationKey = $this->uuid($operationKey);

        return DB::transaction(function () use (
            $actor, $orderKey, $operationKey
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->first(['id']);

            if ($order === null) {
                throw new DomainException('COD order does not exist');
            }

            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($intent === null) {
                throw new DomainException('COD TTN intent does not exist');
            }

            $hash = $this->digest([
                'cod-ttn-dispatch-plan-v1',
                $orderKey,
                $operationKey,
                $actor,
                (string) $intent->context_hmac,
            ]);

            $existing = DB::table('checkout_cod_ttn_dispatch_plans')
                ->where('operation_key', $operationKey)
                ->first();

            if ($existing !== null) {
                if (
                    (int) $existing->intent_id !== (int) $intent->id
                    || (int) $existing->prepared_by !== $actor
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $hash
                    )
                ) {
                    throw new DomainException(
                        'Dispatch operation key reused for different request'
                    );
                }

                return $this->presentPlan($existing);
            }

            if (
                DB::table('checkout_cod_ttn_dispatch_plans')
                    ->where('intent_id', $intent->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Dispatch plan already exists for this intent'
                );
            }

            $readiness = $this->readiness->inspect($orderKey);

            if (
                $readiness['local_context_current'] !== true
                || $readiness['events'] !== []
                || (int) $readiness['intent_id'] !== (int) $intent->id
                || !hash_equals(
                    (string) $intent->context_hmac,
                    (string) $readiness['context_hmac']
                )
            ) {
                throw new DomainException(
                    'Cannot prepare dispatch plan from stale or held intent'
                );
            }

            $id = DB::table('checkout_cod_ttn_dispatch_plans')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'order_id' => $order->id,
                    'intent_id' => $intent->id,
                    'context_hmac' => (string) $intent->context_hmac,
                    'prepared_by' => $actor,
                    'request_hmac' => $hash,
                    'created_at' => now(),
                ]);

            return $this->presentPlan(
                DB::table('checkout_cod_ttn_dispatch_plans')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(
        string $planKey,
        string $operationKey,
        string $decision,
        string $reason
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);
        $planKey = $this->uuid($planKey);
        $operationKey = $this->uuid($operationKey);

        $valid = (
                $decision === 'reviewed_for_future_submission'
                && $reason === 'local_plan_checked'
            ) || (
                $decision === 'rejected'
                && in_array(
                    $reason,
                    ['stale_or_conflicting_data', 'manual_risk_rejection'],
                    true
                )
            );

        if (!$valid) {
            throw new InvalidArgumentException(
                'Invalid dispatch review decision and reason'
            );
        }

        return DB::transaction(function () use (
            $actor,
            $planKey,
            $operationKey,
            $decision,
            $reason
        ): array {
            $plan = DB::table('checkout_cod_ttn_dispatch_plans')
                ->where('operation_key', $planKey)
                ->lockForUpdate()
                ->first();

            if ($plan === null) {
                throw new DomainException('Dispatch plan not found');
            }

            if ((int) $plan->prepared_by === $actor) {
                throw new DomainException(
                    'Dispatch plan requires independent reviewer'
                );
            }

            $hash = $this->digest([
                'cod-ttn-dispatch-review-v1',
                $planKey,
                $operationKey,
                $actor,
                $decision,
                $reason,
                (string) $plan->context_hmac,
            ]);

            $previous = DB::table('checkout_cod_ttn_dispatch_reviews')
                ->where('plan_id', $plan->id)
                ->first();

            if ($previous !== null) {
                if (
                    (string) $previous->operation_key !== $operationKey
                    || !hash_equals(
                        (string) $previous->request_hmac,
                        $hash
                    )
                ) {
                    throw new DomainException(
                        'Dispatch plan already reviewed'
                    );
                }

                return $this->presentReview($previous);
            }

            if (
                DB::table('checkout_cod_ttn_dispatch_reviews')
                    ->where('operation_key', $operationKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Dispatch review operation key already used'
                );
            }

            $orderKey = (string) DB::table('orders')
                ->where('id', $plan->order_id)
                ->value('order_key');

            $readiness = $this->readiness->inspect($orderKey);

            if (
                $readiness['local_context_current'] !== true
                || $readiness['events'] !== []
                || (int) $readiness['intent_id'] !== (int) $plan->intent_id
                || !hash_equals(
                    (string) $readiness['context_hmac'],
                    (string) $plan->context_hmac
                )
            ) {
                throw new DomainException(
                    'Cannot review a stale or held dispatch plan'
                );
            }

            $id = DB::table('checkout_cod_ttn_dispatch_reviews')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'plan_id' => $plan->id,
                    'decision' => $decision,
                    'reason_code' => $reason,
                    'reviewed_by' => $actor,
                    'request_hmac' => $hash,
                    'created_at' => now(),
                ]);

            return $this->presentReview(
                DB::table('checkout_cod_ttn_dispatch_reviews')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException(
                'Invalid dispatch operation UUID'
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
                'Dispatch planning permission denied'
            );
        }

        return (int) $id;
    }

    private function digest(array $payload): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new DomainException('Application key is missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $secret
        );
    }

    /** @return array<string,mixed> */
    private function presentPlan(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'intent_id' => (int) $row->intent_id,
            'prepared_by' => (int) $row->prepared_by,
            'provider_call_made' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'plan_id' => (int) $row->plan_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'carrier_permission_proven' => false,
            'ttn_submission_allowed' => false,
        ];
    }
}
