<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Permission-gated, PII-free read model for COD TTN resolution. */
final class CheckoutCodTtnResolutionStatusService
{
    public function __construct(
        private readonly CheckoutCodTtnEvidenceMatrixService $matrix
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
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
                'COD TTN resolution view permission denied'
            );
        }

        $report = $this->matrix->inspect($orderKey);

        $snapshot = DB::table('checkout_cod_ttn_resolution_snapshots')
            ->where('intent_id', $report['intent_id'])
            ->orderByDesc('id')
            ->first();

        $review = $snapshot === null
            ? null
            : DB::table('checkout_cod_ttn_resolution_reviews')
                ->where('snapshot_id', $snapshot->id)
                ->first(['decision', 'reason_code']);

        $current = $snapshot !== null
            && (int) $snapshot->trigger_event_id
            === (int) $report['trigger_event_id']
            && hash_equals(
                (string) $snapshot->evidence_hmac,
                (string) $report['evidence_hmac']
            );

        $state = match (true) {
            $snapshot === null => 'awaiting_resolution_snapshot',
            !$current => 'evidence_changed_new_snapshot_required',
            $review === null => 'awaiting_independent_review',
            default => 'reviewed_hold_continues',
        };

        return [
            'intent_id' => $report['intent_id'],
            'trigger_event_id' => $report['trigger_event_id'],
            'evidence_outcome' => $report['outcome'],
            'scans_count' => $report['scans_count'],
            'case_archived' => $report['case_archived'],
            'snapshot_id' => $snapshot?->id,
            'snapshot_is_current' => $current,
            'review_decision' => $review?->decision,
            'status' => $state,
            'carrier_ownership_verified' => false,
            'absence_proven' => false,
            'reconciliation_hold_released' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
