<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** CRM projection: no bank references, IBANs, tokens or payment posting. */
final class CheckoutCodBankCoverageStatusService
{
    public function __construct(private readonly CheckoutCodBankCoverageEvaluator $evaluator)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(string $kind, string $sourceKey): array
    {
        $this->authorize();
        if (!in_array($kind, ['single', 'batch'], true) || !Str::isUuid($sourceKey)) {
            throw new InvalidArgumentException('Invalid bank coverage status source');
        }
        $sourceKey = strtolower($sourceKey);
        $current = $this->evaluator->inspect($kind, $sourceKey);
        $row = DB::table('checkout_cod_bank_coverage_packets as p')
            ->leftJoin('checkout_cod_bank_coverage_reviews as r', 'r.packet_id', '=', 'p.id')
            ->where('p.source_kind', $kind)->where('p.source_id', $current['source_id'])
            ->orderByDesc('p.id')->first([
                'p.id', 'p.operation_key', 'p.snapshot_hmac',
                'r.decision',
            ]);
        $fresh = $row !== null && hash_equals(
                (string) $row->snapshot_hmac, (string) $current['snapshot_hmac']
            );
        $status = match (true) {
            $row === null => 'awaiting_coverage_packet',
            $row->decision === 'escalate_investigation' => 'coverage_investigation_escalated',
            !$fresh => 'coverage_stale_requires_recheck',
            $row->decision === 'retain_unverified' => 'coverage_reviewed_external_confirmation_required',
            default => 'awaiting_independent_coverage_review',
        };
        return [
            'source_kind' => $kind,
            'source_id' => $current['source_id'],
            'packet_id' => $row === null ? null : (int) $row->id,
            'status' => $status,
            'result_code' => $current['result_code'],
            'expected_orders_count' => $current['expected_orders_count'],
            'ready_orders_count' => $current['ready_orders_count'],
            'gross_minor' => $current['gross_minor'],
            'fee_minor' => $current['fee_minor'],
            'bank_net_minor' => $current['bank_net_minor'],
            'allocated_minor' => $current['allocated_minor'],
            'snapshot_current' => $fresh,
            'review_decision' => $row?->decision,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function recent(int $limit = 25, ?int $beforeId = null): array
    {
        $this->authorize();
        if ($limit < 1 || $limit > 50 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('Invalid bank coverage queue cursor');
        }
        $q = DB::table('checkout_cod_bank_coverage_packets as p')
            ->leftJoin('checkout_cod_bank_coverage_reviews as r', 'r.packet_id', '=', 'p.id')
            ->orderByDesc('p.id');
        if ($beforeId !== null) {
            $q->where('p.id', '<', $beforeId);
        }
        $rows = $q->limit($limit + 1)->get([
            'p.id', 'p.source_kind', 'p.source_id', 'p.expected_orders_count',
            'p.gross_minor', 'p.fee_minor', 'p.allocated_minor', 'r.decision',
        ]);
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn (object $row): array => [
            'packet_id' => (int) $row->id,
            'source_kind' => (string) $row->source_kind,
            'source_id' => (int) $row->source_id,
            'orders_count' => (int) $row->expected_orders_count,
            'gross_minor' => (int) $row->gross_minor,
            'fee_minor' => (int) $row->fee_minor,
            'allocated_minor' => (int) $row->allocated_minor,
            'review_decision' => $row->decision,
            'payment_posting_allowed' => false,
        ])->values()->all();
        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['packet_id'] : null,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException('Bank coverage read permission denied');
        }
    }
}
