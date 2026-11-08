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
 * Stage an immutable INTERNAL TTN intent for human review.
 * The preflight is deliberately blocked for real provider submission.
 * This class has NO HTTP, queue dispatch or InternetDocument/save call.
 */
final class CheckoutCodTtnSubmissionIntentService
{
    public const PREPARE_ABILITY = 'nova-poshta.ttn-safety.prepare';

    public function __construct(
        private readonly CheckoutCodTtnAuthorizationPreflightService $preflight,
        private readonly CheckoutCodTtnIntentFingerprint $fingerprints,
    ) {
    }

    /** @return array<string, mixed> */
    public function prepare(string $orderKey, string $intentKey): array
    {
        $actor = $this->actor();
        if (!Str::isUuid($orderKey) || !Str::isUuid($intentKey)) {
            throw new InvalidArgumentException('Invalid TTN intent UUID');
        }
        $orderKey = strtolower($orderKey);
        $intentKey = strtolower($intentKey);

        return DB::transaction(function () use ($actor, $orderKey, $intentKey): array {
            $order = DB::table('orders')->where('order_key', $orderKey)
                ->lockForUpdate()->first(['id', 'payment_status']);
            if ($order === null) {
                throw new DomainException('COD order does not exist');
            }
            $orderId = (int) $order->id;

            // Historical retry is safe even when checkout subsequently expired.
            $existing = DB::table('checkout_cod_ttn_submission_intents')
                ->where('order_id', $orderId)->first();
            if ($existing !== null) {
                $expected = $this->digest([
                    'cod-ttn-intent-request-v1', $orderKey, $intentKey, $actor,
                    (string) $existing->context_hmac,
                ]);
                if ((string) $existing->intent_key !== $intentKey
                    || (int) $existing->prepared_by !== $actor
                    || !hash_equals((string) $existing->request_hmac, $expected)
                ) {
                    throw new DomainException('COD order already has an immutable TTN intent');
                }
                return $this->present($existing);
            }
            if ($order->payment_status !== 'pending') {
                throw new DomainException('TTN intent requires pending order');
            }
            if (DB::table('checkout_cod_ttn_submission_intents')
                ->where('intent_key', $intentKey)->exists()
            ) {
                throw new DomainException('TTN intent UUID is already assigned');
            }

            $report = $this->preflight->inspect($orderKey);
            if (($report['all_local_pre_submission_checks_current'] ?? null) !== true) {
                throw new DomainException('TTN intent requires complete local evidence');
            }
            // NOTE: real TTN authorization remains FALSE, by design.
            $context = $this->fingerprints->capture($orderKey, $report);
            $ids = $context['ids'];
            $hmac = $this->digest([
                'cod-ttn-intent-request-v1', $orderKey, $intentKey, $actor,
                $context['context_hmac'],
            ]);

            $id = DB::table('checkout_cod_ttn_submission_intents')->insertGetId([
                'intent_key' => $intentKey,
                'order_id' => $ids['order_id'],
                'draft_id' => $ids['draft_id'],
                'binding_id' => $ids['binding_id'],
                'observation_id' => $ids['observation_id'],
                'sender_profile_id' => $ids['sender_profile_id'],
                'context_hmac' => $context['context_hmac'],
                'request_hmac' => $hmac,
                'prepared_by' => $actor,
                'created_at' => now(),
            ]);
            return $this->present(DB::table('checkout_cod_ttn_submission_intents')
                ->where('id', $id)->first());
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }
        $row = DB::table('checkout_cod_ttn_submission_intents')
            ->where('order_id', $orderId)->first();
        return $row === null ? null : $this->present($row);
    }

    private function actor(): int
    {
        $user = Auth::user();
        if ($user === null || !method_exists($user, 'getAuthIdentifier')) {
            throw new AuthorizationException('Authenticated operator required');
        }
        $id = $user->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::PREPARE_ABILITY)
        ) {
            throw new AuthorizationException('TTN preparation permission denied');
        }
        return (int) $id;
    }

    private function digest(array $payload): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('Application key is missing');
        }
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'intent_key' => (string) $row->intent_key,
            'order_id' => (int) $row->order_id,
            'draft_id' => (int) $row->draft_id,
            'binding_id' => (int) $row->binding_id,
            'observation_id' => (int) $row->observation_id,
            'sender_profile_id' => (int) $row->sender_profile_id,
            'context_hmac' => (string) $row->context_hmac,
            'prepared_by' => (int) $row->prepared_by,
            'status' => 'prepared_not_authorized',
            'provider_call_made' => false,
            'ttn_submission_allowed' => false,
            'actual_ttn_number' => null,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
