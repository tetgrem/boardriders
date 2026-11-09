<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read-only historical projection.
 * Does not contact the carrier or alter an order.
 */
final class CheckoutCodTtnEvidenceMatrixService
{
    public function __construct(
        private readonly CheckoutCodTtnEvidenceMatrixPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid COD order UUID');
        }

        $order = DB::table('orders')
            ->where('order_key', strtolower($orderKey))
            ->first(['id']);

        if ($order === null) {
            throw new DomainException('COD order not found');
        }

        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where('order_id', $order->id)
            ->first(['id']);

        if ($intent === null) {
            throw new DomainException('COD TTN intent not prepared');
        }

        return $this->forIntent((int) $intent->id);
    }

    /** @return array<string,mixed> */
    public function forIntent(int $intentId): array
    {
        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where('id', $intentId)
            ->first(['id', 'order_id', 'context_hmac']);

        if ($intent === null) {
            throw new DomainException('COD TTN intent not found');
        }

        $events = DB::table('checkout_cod_ttn_submission_safety_events')
            ->where('intent_id', $intentId)
            ->orderBy('id')
            ->get(['id', 'event_type']);

        if ($events->contains('event_type', 'cancel_before_submission')) {
            throw new DomainException(
                'Cancelled TTN intent cannot be resolved'
            );
        }

        $trigger = $events
            ->where('event_type', 'reconciliation_required')
            ->last();

        if ($trigger === null) {
            throw new DomainException(
                'No reconciliation hold exists for this intent'
            );
        }

        $scans = DB::table('checkout_cod_ttn_reconciliation_scans')
            ->where('intent_id', $intentId)
            ->orderBy('id')
            ->get([
                'id',
                'trigger_event_id',
                'candidate_number_hmac',
                'intent_context_hmac',
                'provider_result',
            ]);

        $scanIds = $scans->pluck('id')->all();

        $reviews = $scanIds === []
            ? collect()
            : DB::table('checkout_cod_ttn_reconciliation_reviews')
                ->whereIn('scan_id', $scanIds)
                ->get(['id', 'scan_id', 'decision'])
                ->keyBy('scan_id');

        $comparisons = $scanIds === []
            ? collect()
            : DB::table('checkout_cod_ttn_document_comparisons')
                ->whereIn('scan_id', $scanIds)
                ->get([
                    'id',
                    'scan_id',
                    'summary',
                    'document_ref_hmac',
                ])
                ->keyBy('scan_id');

        $input = [];
        $history = [];

        foreach ($scans as $scan) {
            $review = $reviews->get($scan->id);
            $comparison = $comparisons->get($scan->id);

            $contextMatches = hash_equals(
                    (string) $intent->context_hmac,
                    (string) $scan->intent_context_hmac
                ) && (
                    (int) $scan->trigger_event_id === (int) $trigger->id
                );

            $input[] = [
                'provider_result' => (string) $scan->provider_result,
                'number_hmac' => (string) $scan->candidate_number_hmac,
                'context_matches' => $contextMatches,
                'review_decision' => $review?->decision,
                'comparison_summary' => $comparison?->summary,
                'document_ref_seen' =>
                    $comparison?->document_ref_hmac !== null,
            ];

            $history[] = [
                (int) $scan->id,
                (int) $scan->trigger_event_id,
                (string) $scan->candidate_number_hmac,
                (string) $scan->provider_result,
                (string) $scan->intent_context_hmac,
                $review?->id,
                $review?->decision,
                $comparison?->id,
                $comparison?->summary,
                $comparison?->document_ref_hmac,
            ];
        }

        $case = DB::table('checkout_cod_ttn_investigation_cases')
            ->where('intent_id', $intentId)
            ->first(['id']);

        $caseEvents = $case === null
            ? []
            : DB::table('checkout_cod_ttn_investigation_events')
                ->where('case_id', $case->id)
                ->orderBy('id')
                ->get(['id', 'event_type'])
                ->map(static fn (object $row): array => [
                    (int) $row->id,
                    (string) $row->event_type,
                ])
                ->all();

        $classification = $this->policy->classify($input);

        $payload = [
            'cod-ttn-evidence-matrix-v1',
            $intentId,
            (int) $trigger->id,
            (string) $intent->context_hmac,
            $events->map(static fn (object $row): array => [
                (int) $row->id,
                (string) $row->event_type,
            ])->all(),
            $history,
            $case?->id,
            $caseEvents,
            $classification['outcome'],
        ];

        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new DomainException('Application key is missing');
        }

        return array_merge($classification, [
            'intent_id' => $intentId,
            'order_id' => (int) $intent->order_id,
            'trigger_event_id' => (int) $trigger->id,
            'context_hmac' => (string) $intent->context_hmac,
            'evidence_hmac' => hash_hmac(
                'sha256',
                json_encode($payload, JSON_THROW_ON_ERROR),
                $secret
            ),
            'latest_scan_id' => $scans->last()?->id,
            'case_archived' => collect($caseEvents)->contains(
                static fn (array $row): bool => $row[1] === 'archive'
            ),
            'reconciliation_hold_released' => false,
            'ready_for_dispatch' => false,
        ]);
    }
}
