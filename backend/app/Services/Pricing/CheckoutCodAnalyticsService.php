<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only, date-bounded COD management analytics.
 * Financial postings are BANK CREDIT, not customer COD payment confirmation.
 */
final class CheckoutCodAnalyticsService
{
    public const VIEW_ABILITY = 'cod.operations.analytics.view';

    /** @return array<string,mixed> */
    public function report(?string $from = null, ?string $to = null): array
    {
        $this->authorize();

        return $this->aggregate($from, $to);
    }

    /**
     * Trusted console-only read for scheduled internal monitoring.
     * This method is not exposed by an HTTP route.
     */
    public function systemSnapshot(?string $from = null, ?string $to = null): array
    {
        if (!app()->runningInConsole()) {
            throw new AuthorizationException(
                'System COD analytics are available only to trusted console processes'
            );
        }

        return $this->aggregate($from, $to);
    }

    private function aggregate(?string $from, ?string $to): array
    {
        $asOf = CarbonImmutable::now('UTC');
        $w = CheckoutCodAnalyticsWindow::make($from, $to, $asOf);

        // No cached materialization. Read-committed aggregates may reflect
        // changes between queries; this dashboard is not an accounting close.
        return DB::transaction(function () use ($w, $asOf): array {

            $bank = DB::table('checkout_cod_bank_receipt_postings as p')
                ->join('checkout_cod_journal_drafts as d', 'd.id', '=', 'p.journal_draft_id')
                ->leftJoin('checkout_cod_novapay_register_captures as r',
                    'r.bank_posting_id', '=', 'p.id')
                ->where('p.created_at', '>=', $w->start)
                ->where('p.created_at', '<', $w->endExclusive)
                ->selectRaw(<<<'SQL'
                    COUNT(*) AS postings_count,
                    COALESCE(SUM(p.amount_minor), 0) AS posted_bank_minor,
                    COALESCE(SUM(d.gross_minor), 0) AS journal_gross_minor,
                    COALESCE(SUM(d.fee_minor), 0) AS journal_fee_minor,
                    COUNT(*) FILTER (WHERE r.id IS NOT NULL) AS register_claims_count,
                    COALESCE(SUM(r.gross_minor), 0) AS claimed_register_gross_minor,
                    COALESCE(SUM(r.fee_minor), 0) AS claimed_register_fee_minor,
                    COALESCE(SUM(r.bank_minor), 0) AS claimed_register_net_minor,
                    COUNT(*) FILTER (
                        WHERE d.bank_minor <> p.amount_minor
                    ) AS posting_journal_mismatch_count,
                    COUNT(*) FILTER (
                        WHERE r.id IS NOT NULL AND (
                            r.bank_minor <> p.amount_minor
                            OR r.gross_minor <> d.gross_minor
                            OR r.fee_minor <> d.fee_minor
                        )
                    ) AS register_amount_mismatch_count
                SQL)->first();

            $postings = (int) $bank->postings_count;
            $registries = (int) $bank->register_claims_count;

            $cases = DB::table('checkout_cod_operations_cases as c')
                ->where('c.created_at', '>=', $w->start)
                ->where('c.created_at', '<', $w->endExclusive)
                ->selectRaw(<<<'SQL'
                    COUNT(*) AS cases_count,
                    COUNT(*) FILTER (WHERE c.case_status = 'open') AS open_count,
                    COUNT(*) FILTER (WHERE c.case_status = 'in_progress') AS in_progress_count,
                    COUNT(*) FILTER (WHERE c.case_status = 'waiting_external') AS waiting_external_count,
                    COUNT(*) FILTER (WHERE c.case_status = 'closed_internal_review') AS closed_count,
                    COUNT(*) FILTER (WHERE c.assigned_to IS NOT NULL) AS assigned_count,
                    COUNT(*) FILTER (WHERE c.priority = 'critical') AS critical_count,
                    COUNT(*) FILTER (
                        WHERE c.case_status = 'closed_internal_review'
                        AND c.closed_at <= c.sla_due_at
                    ) AS closed_on_time_count,
                    COALESCE(SUM(
                        CASE WHEN c.case_status = 'closed_internal_review'
                        THEN GREATEST(0,
                            FLOOR(EXTRACT(EPOCH FROM (c.closed_at - c.created_at)) / 60)
                        )::bigint ELSE 0 END
                    ), 0) AS closed_duration_minutes
                SQL)->first();

            $overdue = DB::table('checkout_cod_operations_cases')
                ->where('created_at', '>=', $w->start)
                ->where('created_at', '<', $w->endExclusive)
                ->where('case_status', '<>', 'closed_internal_review')
                ->where('sla_due_at', '<', $asOf)
                ->count();

            $caseCount = (int) $cases->cases_count;
            $closed = (int) $cases->closed_count;
            $onTime = (int) $cases->closed_on_time_count;

            $alerts = DB::table('checkout_cod_case_alert_outbox as a')
                ->where('a.created_at', '>=', $w->start)
                ->where('a.created_at', '<', $w->endExclusive)
                ->selectRaw(<<<'SQL'
                    COUNT(*) AS alerts_created,
                    COUNT(*) FILTER (WHERE a.delivered_at IS NOT NULL) AS alerts_delivered,
                    COUNT(*) FILTER (WHERE a.suppressed_at IS NOT NULL) AS alerts_suppressed,
                    COUNT(*) FILTER (
                        WHERE a.delivered_at IS NULL AND a.suppressed_at IS NULL
                    ) AS alerts_pending,
                    COUNT(*) FILTER (WHERE a.alert_kind = 'unassigned_30m') AS unassigned_30m,
                    COUNT(*) FILTER (WHERE a.alert_kind = 'due_soon') AS due_soon,
                    COUNT(*) FILTER (WHERE a.alert_kind = 'overdue') AS overdue,
                    COUNT(*) FILTER (WHERE a.alert_kind = 'overdue_4h') AS overdue_4h,
                    COUNT(*) FILTER (WHERE a.alert_kind = 'overdue_24h') AS overdue_24h
                SQL)->first();

            $inbox = DB::table('checkout_cod_case_alert_inbox')
                ->where('created_at', '>=', $w->start)
                ->where('created_at', '<', $w->endExclusive)
                ->selectRaw(<<<'SQL'
                    COUNT(*) AS inbox_items,
                    COUNT(*) FILTER (WHERE read_at IS NOT NULL) AS inbox_read
                SQL)->first();

            $alertCount = (int) $alerts->alerts_created;
            $staff = $this->staff($w, $asOf);
            $days = $this->daily($w);

            return [
                'period' => [
                    'from' => $w->from,
                    'to' => $w->to,
                    'timezone' => 'UTC',
                    'days' => $w->days,
                    'as_of_utc' => $asOf->toIso8601String(),
                    'cohort_note' => 'Postings, cases and alerts are grouped independently by their own created_at. Case states and SLA use the current records and report timestamp.',
                    'consistency' => 'live_read_committed_not_accounting_snapshot',
                ],
                'financial' => [
                    'currency_code' => 'UAH',
                    'postings_count' => $postings,
                    'posted_bank_minor' => (int) $bank->posted_bank_minor,
                    'journal_gross_minor' => (int) $bank->journal_gross_minor,
                    'journal_fee_minor' => (int) $bank->journal_fee_minor,
                    'register_claims_count' => $registries,
                    'missing_register_count' => $postings - $registries,
                    'register_coverage_bp' => CheckoutCodAnalyticsMath::percentBasisPoints($registries, $postings),
                    'claimed_register_gross_minor' => (int) $bank->claimed_register_gross_minor,
                    'claimed_register_fee_minor' => (int) $bank->claimed_register_fee_minor,
                    'claimed_register_net_minor' => (int) $bank->claimed_register_net_minor,
                    'posting_journal_mismatch_count' => (int) $bank->posting_journal_mismatch_count,
                    'register_amount_mismatch_count' => (int) $bank->register_amount_mismatch_count,
                    'customer_paid_minor_verified' => null,
                    'revenue_minor_verified' => null,
                ],
                'cases' => [
                    'created_count' => $caseCount,
                    'open_count' => (int) $cases->open_count,
                    'in_progress_count' => (int) $cases->in_progress_count,
                    'waiting_external_count' => (int) $cases->waiting_external_count,
                    'closed_internal_review_count' => $closed,
                    'currently_assigned_count' => (int) $cases->assigned_count,
                    'critical_priority_count' => (int) $cases->critical_count,
                    'active_overdue_count' => (int) $overdue,
                    'closed_within_sla_count' => $onTime,
                    'closed_sla_hit_rate_bp' => CheckoutCodAnalyticsMath::percentBasisPoints($onTime, $closed),
                    'mean_closed_duration_minutes' => CheckoutCodAnalyticsMath::averageMinutes(
                        (int) $cases->closed_duration_minutes, $closed
                    ),
                    'historical_sla_revisions_included' => false,
                    'live_evidence_freshness_checked' => false,
                ],
                'notifications' => [
                    'outbox_created_count' => $alertCount,
                    'outbox_delivered_count' => (int) $alerts->alerts_delivered,
                    'outbox_suppressed_count' => (int) $alerts->alerts_suppressed,
                    'outbox_pending_count' => (int) $alerts->alerts_pending,
                    'crm_delivery_rate_bp' => CheckoutCodAnalyticsMath::percentBasisPoints(
                        (int) $alerts->alerts_delivered, $alertCount
                    ),
                    'by_kind' => [
                        'unassigned_30m' => (int) $alerts->unassigned_30m,
                        'due_soon' => (int) $alerts->due_soon,
                        'overdue' => (int) $alerts->overdue,
                        'overdue_4h' => (int) $alerts->overdue_4h,
                        'overdue_24h' => (int) $alerts->overdue_24h,
                    ],
                    'crm_inbox_items_created_count' => (int) $inbox->inbox_items,
                    'crm_inbox_read_count' => (int) $inbox->inbox_read,
                    'external_channels_delivered_verified' => false,
                ],
                'staff' => $staff,
                'daily' => $days,
                'automatic_payment_posting_allowed' => false,
                'orders_payment_status_changed' => false,
                'cod_paid' => false,
            ];
        }, 1);
    }

    /** @return array{items:list<array<string,mixed>>,truncated:bool,limit:int} */
    private function staff(CheckoutCodAnalyticsWindow $w, CarbonImmutable $asOf): array
    {
        $rows = DB::table('checkout_cod_operations_cases as c')
            ->where('c.created_at', '>=', $w->start)
            ->where('c.created_at', '<', $w->endExclusive)
            ->whereNotNull('c.assigned_to')
            ->groupBy('c.assigned_to')
            ->selectRaw(<<<'SQL'
                c.assigned_to AS actor_id,
                COUNT(*) AS assigned_cases_count,
                COUNT(*) FILTER (
                    WHERE c.case_status <> 'closed_internal_review'
                ) AS active_cases_count,
                COUNT(*) FILTER (
                    WHERE c.case_status = 'closed_internal_review'
                ) AS closed_cases_count,
                COUNT(*) FILTER (
                    WHERE c.case_status = 'closed_internal_review'
                      AND c.closed_at <= c.sla_due_at
                ) AS closed_on_time_count,
                COUNT(*) FILTER (
                    WHERE c.case_status <> 'closed_internal_review'
                      AND c.sla_due_at < ?
                ) AS overdue_active_count
            SQL, [$asOf])
            ->orderByDesc('assigned_cases_count')
            ->orderBy('c.assigned_to')
            ->limit(101)
            ->get();

        $truncated = $rows->count() > 100;
        $items = $rows->take(100)->map(static function (object $r): array {
            $closed = (int) $r->closed_cases_count;
            return [
                'actor_id' => (int) $r->actor_id,
                'assigned_cases_count' => (int) $r->assigned_cases_count,
                'active_cases_count' => (int) $r->active_cases_count,
                'closed_cases_count' => $closed,
                'closed_on_time_count' => (int) $r->closed_on_time_count,
                'closed_sla_hit_rate_bp' => CheckoutCodAnalyticsMath::percentBasisPoints(
                    (int) $r->closed_on_time_count, $closed
                ),
                'overdue_active_count' => (int) $r->overdue_active_count,
            ];
        })->values()->all();

        return ['items' => $items, 'truncated' => $truncated, 'limit' => 100];
    }

    /** @return list<array{date:string,postings_count:int,posted_bank_minor:int,cases_created_count:int,alerts_created_count:int}> */
    private function daily(CheckoutCodAnalyticsWindow $w): array
    {
        $result = [];
        for ($day = $w->start; $day->lessThan($w->endExclusive); $day = $day->addDay()) {
            $date = $day->toDateString();
            $result[$date] = [
                'date' => $date,
                'postings_count' => 0,
                'posted_bank_minor' => 0,
                'cases_created_count' => 0,
                'alerts_created_count' => 0,
            ];
        }

        $postingDays = DB::table('checkout_cod_bank_receipt_postings as p')
            ->where('p.created_at', '>=', $w->start)
            ->where('p.created_at', '<', $w->endExclusive)
            ->selectRaw("DATE(p.created_at AT TIME ZONE 'UTC') AS day, COUNT(*) AS n, COALESCE(SUM(p.amount_minor), 0) AS net")
            ->groupByRaw("DATE(p.created_at AT TIME ZONE 'UTC')")
            ->get();
        foreach ($postingDays as $r) {
            $date = (string) $r->day;
            $result[$date]['postings_count'] = (int) $r->n;
            $result[$date]['posted_bank_minor'] = (int) $r->net;
        }

        $caseDays = DB::table('checkout_cod_operations_cases as c')
            ->where('c.created_at', '>=', $w->start)
            ->where('c.created_at', '<', $w->endExclusive)
            ->selectRaw("DATE(c.created_at AT TIME ZONE 'UTC') AS day, COUNT(*) AS n")
            ->groupByRaw("DATE(c.created_at AT TIME ZONE 'UTC')")
            ->get();
        foreach ($caseDays as $r) {
            $result[(string) $r->day]['cases_created_count'] = (int) $r->n;
        }

        $alertDays = DB::table('checkout_cod_case_alert_outbox as a')
            ->where('a.created_at', '>=', $w->start)
            ->where('a.created_at', '<', $w->endExclusive)
            ->selectRaw("DATE(a.created_at AT TIME ZONE 'UTC') AS day, COUNT(*) AS n")
            ->groupByRaw("DATE(a.created_at AT TIME ZONE 'UTC')")
            ->get();
        foreach ($alertDays as $r) {
            $result[(string) $r->day]['alerts_created_count'] = (int) $r->n;
        }

        return array_values($result);
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
            || !Gate::forUser($user)->allows(self::VIEW_ABILITY)) {
            throw new AuthorizationException('COD financial analytics access denied');
        }
    }
}
