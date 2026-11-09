<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** PII-free tracking read model. Observed code is never an OMS transition. */
final class CheckoutCodTtnTrackingStatusService
{
    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $this->authorize();

        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid order UUID');
        }

        $order = DB::table('orders')
            ->where('order_key', strtolower($orderKey))
            ->first(['id', 'payment_status']);

        if ($order === null) {
            throw new DomainException('Order not found');
        }

        $case = DB::table('checkout_cod_ttn_tracking_cases as c')
            ->join(
                'checkout_cod_ttn_submission_intents as i',
                'i.id',
                '=',
                'c.intent_id'
            )
            ->where('i.order_id', $order->id)
            ->first(['c.id', 'c.tracking_key']);

        $base = [
            'order_id' => (int) $order->id,
            'tracking_case_id' => $case?->id === null
                ? null
                : (int) $case->id,
            'tracking_key' => $case?->tracking_key,
            'latest_query_result' => null,
            'latest_observed_status_code' => null,
            'last_query_started_at' => null,
            'observations_count' => 0,
            'status_changed_since_previous_success' => false,
            'tracking_candidate_only' => true,
            'carrier_ownership_verified' => false,
            'delivery_confirmed_by_tracking' => false,
            'cod_paid_by_tracking' => false,
            'inventory_shipped' => false,
            'reconciliation_hold_released' => false,
            'ttn_submission_allowed' => false,
        ];

        if ($case === null) {
            return $base + ['status' => 'no_tracking_candidate'];
        }

        $last = DB::table('checkout_cod_ttn_tracking_observations')
            ->where('tracking_case_id', $case->id)
            ->orderByDesc('observed_started_at')
            ->orderByDesc('id')
            ->first(['outcome', 'observed_started_at']);

        $successful = DB::table('checkout_cod_ttn_tracking_observations')
            ->where('tracking_case_id', $case->id)
            ->where('outcome', 'observed')
            ->orderByDesc('observed_started_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get(['status_code']);

        $latestCode = $successful->first()?->status_code;
        $previousCode = $successful->skip(1)->first()?->status_code;

        $count = DB::table('checkout_cod_ttn_tracking_observations')
            ->where('tracking_case_id', $case->id)
            ->count();

        $status = match ($last?->outcome) {
            null => 'awaiting_first_carrier_query',
            'observed' => 'carrier_status_code_observed',
            'provider_failure' => 'carrier_temporarily_unavailable',
            default => 'carrier_response_inconclusive',
        };

        return array_merge($base, [
            'status' => $status,
            'latest_query_result' => $last?->outcome,
            'latest_observed_status_code' => $latestCode,
            'last_query_started_at' => $last?->observed_started_at === null
                ? null
                : (string) $last->observed_started_at,
            'observations_count' => $count,
            'status_changed_since_previous_success' =>
                $previousCode !== null && $latestCode !== $previousCode,
        ]);
    }

    /** @return array<string,mixed> */
    public function history(
        string $trackingKey,
        int $limit = 50,
        ?int $beforeId = null
    ): array {
        $this->authorize();

        if (
            !Str::isUuid($trackingKey)
            || $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId <= 0)
        ) {
            throw new InvalidArgumentException(
                'Invalid tracking history parameters'
            );
        }

        $case = DB::table('checkout_cod_ttn_tracking_cases')
            ->where('tracking_key', strtolower($trackingKey))
            ->first(['id']);

        if ($case === null) {
            throw new DomainException('Tracking case not found');
        }

        $query = DB::table('checkout_cod_ttn_tracking_observations')
            ->where('tracking_case_id', $case->id)
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'id',
            'outcome',
            'status_code',
            'observed_started_at',
            'observed_by',
        ]);

        $more = $rows->count() > $limit;

        $items = $rows
            ->take($limit)
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'outcome' => (string) $row->outcome,
                'status_code' => $row->status_code,
                'started_at' => (string) $row->observed_started_at,
                'observed_by' => (int) $row->observed_by,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['id']
                : null,
            'ttn_linked_to_order' => false,
            'cod_paid' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Tracking report permission denied'
            );
        }
    }
}
