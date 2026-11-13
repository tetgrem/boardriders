<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Read-only consolidated COD leadership console.
 *
 * Source workflows retain ownership of acknowledgements,
 * reviews, publication and payment decisions.
 */
final class CheckoutCodFinanceCenterService
{
    public const VIEW_ABILITY = 'cod.operations.finance_center.view';
    public const METRICS_ABILITY = 'cod.operations.finance_center.metrics';

    public function __construct(
        private readonly CheckoutCodFinanceCenterCursor $cursors,
        private readonly CheckoutCodAnalyticsService $analytics
    ) {
    }

    /** @return array<string,mixed> */
    public function queue(
        string $source = 'all',
        string $severity = 'all',
        bool $unacknowledged = false,
        int $limit = 20,
        ?string $cursor = null
    ): array {
        $user = $this->authorizeView();

        $this->validateFilters($source, $severity, $limit);

        $fingerprint = $this->cursors->fingerprint(
            $source,
            $severity,
            $unacknowledged
        );

        $after = $cursor === null
            ? null
            : $this->cursors->decode($cursor, $fingerprint);

        $query = $this->filteredQuery(
            $user,
            $source,
            $severity,
            $unacknowledged
        );

        if ($query === null) {
            return $this->emptyQueue(
                $source,
                $severity,
                $unacknowledged
            );
        }

        if ($after !== null) {
            $this->after($query, $after);
        }

        $rows = $query
            ->orderByDesc('u.priority')
            ->orderByDesc('u.observed_at')
            ->orderBy('u.source')
            ->orderByDesc('u.source_id')
            ->limit($limit + 1)
            ->get(['u.*']);

        $shown = $rows->take($limit);

        $items = $shown
            ->map(fn (object $r): array => $this->formatItem($r))
            ->values()
            ->all();

        $last = $shown->last();

        $next = $rows->count() > $limit && $last !== null
            ? $this->cursors->encode([
                'priority' => (int) $last->priority,
                'observed_at' => (string) $last->observed_at,
                'source' => (string) $last->source,
                'source_id' => (int) $last->source_id,
            ], $fingerprint)
            : null;

        return [
            'filters' => compact(
                'source',
                'severity',
                'unacknowledged'
            ),
            'items' => $items,
            'next_cursor' => $next,
            'snapshot_is_live' => true,
            'actions_must_use_source_workflow' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $user = $this->authorizeView();

        $query = $this->filteredQuery(
            $user,
            'all',
            'all',
            false
        );

        $source = array_fill_keys(
            ['case', 'case_alert', 'monitor', 'sla'],
            0
        );

        $priority = [
            'critical' => 0,
            'high' => 0,
            'warning' => 0,
        ];

        $unacknowledged = 0;

        if ($query !== null) {
            $counts = $query
                ->selectRaw(
                    'u.source, u.priority,
                     COUNT(*) AS total,
                     COUNT(*) FILTER (
                         WHERE u.acknowledged = FALSE
                     ) AS unread'
                )
                ->groupBy('u.source', 'u.priority')
                ->get();

            foreach ($counts as $c) {
                $n = (int) $c->total;

                $source[(string) $c->source] += $n;

                $priority[
                $this->priorityLabel((int) $c->priority)
                ] += $n;

                $unacknowledged += (int) $c->unread;
            }
        }

        return [
            'active_items' => array_sum($source),
            'unacknowledged' => $unacknowledged,
            'by_source' => $source,
            'by_severity' => $priority,
            'source_meanings' => [
                'case' =>
                    'Open investigation cases past SLA',
                'case_alert' =>
                    'Case-alert outbox not delivered or suppressed after 15 minutes',
                'monitor' =>
                    'Last scanned late financial evidence signal per daily report',
                'sla' =>
                    'Last scanned active correction SLA alert per correction',
            ],
            'snapshot_is_live' => true,
            'accounting_or_payment_status_changed' => false,
        ];
    }

    /**
     * Reuses block 44 analytical data.
     * Never derives payment confirmation.
     */
    public function overview(int $top = 10): array
    {
        $user = $this->authorizeView();

        if (
            !Gate::forUser($user)->allows(self::METRICS_ABILITY)
            || !Gate::forUser($user)->allows(
                CheckoutCodAnalyticsService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Finance center management metrics permission denied'
            );
        }

        if ($top < 1 || $top > 50) {
            throw new InvalidArgumentException(
                'Finance center top must be 1..50'
            );
        }

        $now = CarbonImmutable::now('UTC');

        $from = $now->subDays(29)->toDateString();
        $to = $now->toDateString();

        $analytics = $this->analytics->report($from, $to);

        return [
            'as_of_utc' => $now->toIso8601String(),
            'period' => $analytics['period'],
            'financial' => $analytics['financial'],
            'cases' => $analytics['cases'],
            'notifications' => $analytics['notifications'],
            'staff' => $analytics['staff'],
            'daily' => $analytics['daily'],
            'risk_summary' => $this->summary(),
            'top_risks' => $this->queue(
                'all',
                'all',
                false,
                $top
            )['items'],
            'financial_data_are_not_proof_of_customer_payment' => true,
        ];
    }

    private function filteredQuery(
        object $user,
        string $source,
        string $severity,
        bool $unacknowledged
    ): ?Builder {
        $now = CarbonImmutable::now('UTC');

        $parts = [];

        $include = static fn (string $key): bool =>
            $source === 'all' || $source === $key;

        /*
         * Source 1: overdue COD investigations.
         * Closed internal reviews are not active cases.
         */
        if ($include('case')) {
            $parts[] = DB::table('checkout_cod_operations_cases as c')
                ->selectRaw(
                    "'case'::text AS source,
                     c.id::bigint AS source_id,
                     NULL::text AS context_key,
                     'case_sla_overdue'::text AS code,
                     3::integer AS priority,
                     c.created_at AS observed_at,
                     FALSE AS acknowledged,
                     NULL::text AS target_role"
                )
                ->whereIn(
                    'c.case_status',
                    ['open', 'in_progress', 'waiting_external']
                )
                ->where('c.sla_due_at', '<', $now);
        }

        /*
         * Source 2: outbox delivery failures from block 43.
         * A grace period avoids showing newly queued notifications.
         */
        if ($include('case_alert')) {
            $parts[] = DB::table('checkout_cod_case_alert_outbox as o')
                ->selectRaw(
                    "'case_alert'::text AS source,
                     o.id::bigint AS source_id,
                     NULL::text AS context_key,
                     'case_notification_delivery_pending'::text AS code,
                     2::integer AS priority,
                     o.created_at AS observed_at,
                     FALSE AS acknowledged,
                     NULL::text AS target_role"
                )
                ->whereNull('o.delivered_at')
                ->whereNull('o.suppressed_at')
                ->where(
                    'o.created_at',
                    '<=',
                    $now->subMinutes(15)
                );
        }

        $hasDailyAccess = Gate::forUser($user)->allows(
            CheckoutCodDailyReportService::VIEW_ABILITY
        );

        /*
         * Source 3: the latest financial-evidence monitor
         * state from block 47.
         */
        if (
            $include('monitor')
            && $hasDailyAccess
            && Gate::forUser($user)->allows(
                CheckoutCodDailyMonitorService::VIEW_ABILITY
            )
        ) {
            $parts[] = DB::table('checkout_cod_daily_monitor_signals as m')
                ->join(
                    'checkout_cod_daily_reports as r',
                    'r.id',
                    '=',
                    'm.report_id'
                )
                ->leftJoin(
                    'checkout_cod_daily_monitor_receipts as ack',
                    'ack.signal_id',
                    '=',
                    'm.id'
                )
                ->selectRaw(
                    "'monitor'::text AS source,
                     m.id::bigint AS source_id,
                     r.report_key::text AS context_key,
                     m.coverage_status::text AS code,
                     CASE
                        WHEN m.severity = 'critical' THEN 3
                        WHEN m.severity = 'high' THEN 2
                        ELSE 1
                     END::integer AS priority,
                     m.created_at AS observed_at,
                     (ack.id IS NOT NULL) AS acknowledged,
                     NULL::text AS target_role"
                )
                ->where('m.severity', '<>', 'none')
                ->whereRaw(
                    'm.id = (
                        SELECT MAX(x.id)
                        FROM checkout_cod_daily_monitor_signals x
                        WHERE x.report_id = m.report_id
                    )'
                );
        }

        /*
         * Source 4: current correction SLA alerts.
         * Role-based visibility must match block 48.
         */
        if (
            $include('sla')
            && $hasDailyAccess
            && Gate::forUser($user)->allows(
                CheckoutCodCorrectionSlaInboxService::VIEW_ABILITY
            )
        ) {
            $teams = $this->slaTeams($user);

            if ($teams !== []) {
                $parts[] = DB::table(
                    'checkout_cod_correction_sla_alerts as a'
                )
                    ->join(
                        'checkout_cod_daily_corrections as c',
                        'c.id',
                        '=',
                        'a.correction_id'
                    )
                    ->leftJoin(
                        'checkout_cod_correction_sla_receipts as ack',
                        'ack.alert_id',
                        '=',
                        'a.id'
                    )
                    ->selectRaw(
                        "'sla'::text AS source,
                         a.id::bigint AS source_id,
                         c.correction_key::text AS context_key,
                         a.stage::text AS code,
                         CASE
                            WHEN a.severity = 'critical' THEN 3
                            WHEN a.severity = 'high' THEN 2
                            ELSE 1
                         END::integer AS priority,
                         a.created_at AS observed_at,
                         (ack.id IS NOT NULL) AS acknowledged,
                         a.target_role::text AS target_role"
                    )
                    ->whereIn('a.target_role', $teams)
                    ->whereRaw(
                        'a.id = (
                            SELECT MAX(x.id)
                            FROM checkout_cod_correction_sla_alerts x
                            WHERE x.correction_id = a.correction_id
                        )'
                    )
                    ->whereRaw(
                        "a.phase_event_id = (
                            SELECT MAX(e.id)
                            FROM checkout_cod_daily_correction_events e
                            WHERE e.correction_id = a.correction_id
                              AND e.event_kind IN (
                                  'proposed',
                                  'approved',
                                  'rejected',
                                  'published'
                              )
                        )"
                    );
            }
        }

        if ($parts === []) {
            return null;
        }

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $q = DB::query()->fromSub($union, 'u');

        if ($severity !== 'all') {
            $q->where(
                'u.priority',
                match ($severity) {
                    'critical' => 3,
                    'high' => 2,
                    'warning' => 1,
                }
            );
        }

        if ($unacknowledged) {
            $q->where('u.acknowledged', false);
        }

        return $q;
    }

    /**
     * @param array{
     *   priority:int,
     *   observed_at:string,
     *   source:string,
     *   source_id:int
     * } $cursor
     */
    private function after(Builder $q, array $cursor): void
    {
        $p = $cursor['priority'];
        $t = CarbonImmutable::parse(
            $cursor['observed_at'],
            'UTC'
        );
        $s = $cursor['source'];
        $id = $cursor['source_id'];

        $q->where(function (Builder $w) use ($p, $t, $s, $id): void {
            $w->where('u.priority', '<', $p)
                ->orWhere(
                    function (Builder $a) use ($p, $t): void {
                        $a->where('u.priority', $p)
                            ->where('u.observed_at', '<', $t);
                    }
                )
                ->orWhere(
                    function (Builder $a) use ($p, $t, $s): void {
                        $a->where('u.priority', $p)
                            ->where('u.observed_at', $t)
                            ->where('u.source', '>', $s);
                    }
                )
                ->orWhere(
                    function (Builder $a) use ($p, $t, $s, $id): void {
                        $a->where('u.priority', $p)
                            ->where('u.observed_at', $t)
                            ->where('u.source', $s)
                            ->where('u.source_id', '<', $id);
                    }
                );
        });
    }

    private function slaTeams(object $user): array
    {
        $out = [];

        foreach ([
                     'finance_review' =>
                         CheckoutCodCorrectionSlaInboxService::REVIEW_TEAM_ABILITY,
                     'finance_publication' =>
                         CheckoutCodCorrectionSlaInboxService::PUBLICATION_TEAM_ABILITY,
                     'finance_management' =>
                         CheckoutCodCorrectionSlaInboxService::MANAGEMENT_ABILITY,
                 ] as $team => $permission) {
            if (Gate::forUser($user)->allows($permission)) {
                $out[] = $team;
            }
        }

        return $out;
    }

    private function authorizeView(): object
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
            || !Gate::forUser($user)->allows(self::VIEW_ABILITY)
        ) {
            throw new AuthorizationException(
                'COD finance center access denied'
            );
        }

        return $user;
    }

    private function validateFilters(
        string $source,
        string $severity,
        int $limit
    ): void {
        if (
            !in_array(
                $source,
                ['all', 'case', 'case_alert', 'monitor', 'sla'],
                true
            )
            || !in_array(
                $severity,
                ['all', 'critical', 'high', 'warning'],
                true
            )
            || $limit < 1
            || $limit > 100
        ) {
            throw new InvalidArgumentException(
                'Invalid COD finance center filters'
            );
        }
    }

    private function formatItem(object $r): array
    {
        return [
            'source' => (string) $r->source,
            'source_id' => (int) $r->source_id,
            'context_key' => $r->context_key === null
                ? null
                : (string) $r->context_key,
            'code' => (string) $r->code,
            'severity' => $this->priorityLabel(
                (int) $r->priority
            ),
            'priority' => (int) $r->priority,
            'observed_at' => CarbonImmutable::parse(
                (string) $r->observed_at
            )->utc()->toIso8601String(),
            'acknowledged' => (bool) $r->acknowledged,
            'target_role' => $r->target_role === null
                ? null
                : (string) $r->target_role,
            'read_only' => true,
        ];
    }

    private function priorityLabel(int $priority): string
    {
        return match ($priority) {
            3 => 'critical',
            2 => 'high',
            1 => 'warning',
            default => throw new \LogicException(
                'Unexpected finance center priority'
            ),
        };
    }

    private function emptyQueue(
        string $source,
        string $severity,
        bool $unacknowledged
    ): array {
        return [
            'filters' => compact(
                'source',
                'severity',
                'unacknowledged'
            ),
            'items' => [],
            'next_cursor' => null,
            'snapshot_is_live' => true,
            'actions_must_use_source_workflow' => true,
        ];
    }
}
