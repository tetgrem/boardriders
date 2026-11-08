<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Read-only, permission-gated CRM projection. Never returns recipient PII or TTN numbers. */
final class CheckoutCodTtnInvestigationStatusService
{
    public const VIEW_ABILITY = 'nova-poshta.ttn-investigation.view';

    public function __construct(private readonly CheckoutCodTtnInvestigationPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::VIEW_ABILITY)) {
            throw new AuthorizationException('TTN investigation view permission denied');
        }
        $orderKey = $this->policy->uuid($orderKey);
        $order = DB::table('orders')->where('order_key', $orderKey)->first(['id']);
        if ($order === null) {
            throw new DomainException('TTN investigation order not found');
        }
        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where('order_id', $order->id)->first(['id']);
        $flagged = $intent !== null && DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('intent_id', $intent->id)->where('event_type', 'reconciliation_required')->exists();
        $case = $intent === null ? null : DB::table('checkout_cod_ttn_investigation_cases')
            ->where('intent_id', $intent->id)->first();

        if ($case === null) {
            return [
                'order_id' => (int) $order->id,
                'intent_id' => $intent === null ? null : (int) $intent->id,
                'case_id' => null,
                'status' => $intent === null ? 'no_intent' : ($flagged ? 'not_opened' : 'not_flagged'),
                'reconciliation_hold_released' => false,
                'automatic_retry_allowed' => false,
                'ttn_submission_allowed' => false,
                'ready_for_dispatch' => false,
            ];
        }

        $rows = DB::table('checkout_cod_ttn_investigation_events')
            ->where('case_id', $case->id)->orderBy('id')
            ->get(['id', 'event_type', 'reason_code', 'assignee_id', 'deadline_at', 'recorded_by']);
        $timeline = $rows->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'event_type' => (string) $row->event_type,
            'reason_code' => (string) $row->reason_code,
            'assignee_id' => $row->assignee_id === null ? null : (int) $row->assignee_id,
            'deadline_at' => $row->deadline_at === null ? null : (string) $row->deadline_at,
            'recorded_by' => (int) $row->recorded_by,
        ])->all();
        $project = $this->policy->project((string) $case->deadline_at, $timeline, CarbonImmutable::now('UTC'));
        $latestScan = DB::table('checkout_cod_ttn_reconciliation_scans')
            ->where('intent_id', $intent->id)->orderByDesc('id')->first(['id', 'provider_result']);
        $scanReviewed = $latestScan !== null && DB::table('checkout_cod_ttn_reconciliation_reviews')
                ->where('scan_id', $latestScan->id)->exists();
        $comparison = $latestScan === null ? null : DB::table('checkout_cod_ttn_document_comparisons')
            ->where('scan_id', $latestScan->id)->first(['summary']);
        return array_merge([
            'order_id' => (int) $order->id,
            'intent_id' => (int) $intent->id,
            'case_id' => (int) $case->id,
            'case_key' => (string) $case->case_key,
            'opened_by' => (int) $case->opened_by,
            'event_count' => count($timeline),
            'timeline' => $timeline,
            'latest_scan_result' => $latestScan?->provider_result,
            'latest_scan_reviewed' => $scanReviewed,
            'latest_document_comparison' => $comparison?->summary,
        ], $project);
    }
}
