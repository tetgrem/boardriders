<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CheckoutCodPostingPreflightStatusService
{
    public function __construct(
        private readonly CheckoutCodPostingPreflightEvaluator $evaluator
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $orderKey): array
    {
        $this->authorize();

        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException(
                'Invalid COD order UUID'
            );
        }

        $orderKey = strtolower($orderKey);

        $order = DB::table('orders')
            ->where('order_key', $orderKey)
            ->first(['id']);

        if ($order === null) {
            throw new DomainException('Order not found');
        }

        $row = DB::table(
            'checkout_cod_posting_preflights as p'
        )
            ->join(
                'checkout_cod_statement_reconciliations as s',
                's.id',
                '=',
                'p.reconciliation_id'
            )
            ->leftJoin(
                'checkout_cod_posting_preflight_reviews as r',
                'r.preflight_id',
                '=',
                'p.id'
            )
            ->where('p.order_id', $order->id)
            ->orderByDesc('p.id')
            ->first([
                'p.id',
                'p.operation_key',
                'p.reconciliation_id',
                'p.snapshot_hmac',
                'p.cod_gross_minor',
                'p.fee_minor',
                'p.bank_allocation_minor',
                's.operation_key as reconciliation_key',
                'r.decision',
            ]);

        if ($row === null) {
            return [
                'order_id' => (int) $order->id,
                'status' => 'awaiting_posting_preflight',
                'preflight_id' => null,
                'funds_received_verified' => false,
                'payment_posting_allowed' => false,
                'cod_paid' => false,
            ];
        }

        $fresh = false;

        try {
            $current = $this->evaluator->evaluate(
                (string) $row->reconciliation_key,
                $orderKey
            );

            $fresh = $current['can_prepare_review']
                && hash_equals(
                    (string) $row->snapshot_hmac,
                    (string) $current['snapshot_hmac']
                );
        } catch (DomainException $exception) {
            $fresh = false;
        }

        $status = match (true) {
            $row->decision === 'hold_for_investigation'
            => 'posting_preflight_investigation_hold',
            !$fresh => 'posting_preflight_stale',
            $row->decision === 'retain_unverified'
            => 'reviewed_external_payment_evidence_required',
            default => 'awaiting_independent_posting_preflight_review',
        };

        return [
            'order_id' => (int) $order->id,
            'preflight_id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'reconciliation_id' => (int) $row->reconciliation_id,
            'status' => $status,
            'snapshot_current' => $fresh,
            'review_decision' => $row->decision,
            'cod_gross_minor' => (int) $row->cod_gross_minor,
            'fee_minor' => (int) $row->fee_minor,
            'bank_allocation_minor' =>
                (int) $row->bank_allocation_minor,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function recent(
        int $limit = 25,
        ?int $beforeId = null
    ): array {
        $this->authorize();

        if (
            $limit < 1
            || $limit > 50
            || ($beforeId !== null && $beforeId < 1)
        ) {
            throw new InvalidArgumentException(
                'Invalid COD preflight queue cursor'
            );
        }

        $query = DB::table(
            'checkout_cod_posting_preflights as p'
        )
            ->leftJoin(
                'checkout_cod_posting_preflight_reviews as r',
                'r.preflight_id',
                '=',
                'p.id'
            )
            ->orderByDesc('p.id');

        if ($beforeId !== null) {
            $query->where('p.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'p.id',
            'p.order_id',
            'p.source_kind',
            'p.cod_gross_minor',
            'p.fee_minor',
            'p.bank_allocation_minor',
            'r.decision',
        ]);

        $more = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $row): array => [
                'preflight_id' => (int) $row->id,
                'order_id' => (int) $row->order_id,
                'source_kind' => (string) $row->source_kind,
                'cod_gross_minor' => (int) $row->cod_gross_minor,
                'fee_minor' => (int) $row->fee_minor,
                'bank_allocation_minor' =>
                    (int) $row->bank_allocation_minor,
                'review_decision' => $row->decision,
                'funds_received_verified' => false,
                'payment_posting_allowed' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['preflight_id']
                : null,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id)
                && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'COD posting preflight read permission denied'
            );
        }
    }
}
