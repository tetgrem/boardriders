<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Periodic immutable financial-appendix coverage monitoring; no payment mutations. */
final class CheckoutCodDailyMonitorService
{
    public const VIEW_ABILITY = 'cod.operations.daily_monitor.view';
    public const ACK_ABILITY = 'cod.operations.daily_monitor.acknowledge';

    public function __construct(
        private readonly CheckoutCodDailyCorrectionObservation $observations,
        private readonly CheckoutCodDailyCorrectionSigner $correctionSigner,
        private readonly CheckoutCodDailyMonitorSigner $monitorSigner,
        private readonly CheckoutCodDailyMonitorIntegrity $integrity,
        private readonly CheckoutCodDailyMonitorPolicy $policy
    ) {
    }

    /** Console-only system scan; no service-user impersonation. */
    public function scan(int $limit = 50): array
    {
        if (!app()->runningInConsole()) {
            throw new AuthorizationException('COD daily monitoring scan requires trusted CLI execution');
        }
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('COD monitoring limit must be 1 to 200');
        }

        return DB::transaction(function () use ($limit): array {
            $cursor = DB::table('checkout_cod_daily_monitor_cursor')
                ->where('cursor_name', 'daily_v47')->lockForUpdate()->first();
            if ($cursor === null) {
                throw new DomainException('COD monitoring cursor missing');
            }
            $q = DB::table('checkout_cod_daily_reports')->orderByDesc('id')->limit($limit);
            if ($cursor->last_report_id !== null) {
                $q->where('id', '<', $cursor->last_report_id);
            }
            $reports = $q->get();
            $inserted = 0;
            $attention = 0;

            foreach ($reports as $report) {
                $this->integrity->assertReport($report);
                $observation = $this->observations->observe($report, true);
                $observationHmac = $this->correctionSigner->observation(
                    (string) $report->report_key,
                    (string) $report->payload_hmac,
                    $observation
                );
                $matching = null;
                $otherPublished = false;
                $corrections = DB::table('checkout_cod_daily_corrections')
                    ->where('report_id', $report->id)->orderBy('id')->cursor();

                foreach ($corrections as $correction) {
                    $status = $this->integrity->correctionStatus($correction, $report);
                    if (hash_equals((string) $correction->observation_hmac, $observationHmac)) {
                        $matching = $status;
                    } elseif ($status === 'published') {
                        $otherPublished = true;
                    }
                }

                $decision = $this->policy->decide(
                    $observation['has_changes'], $matching, $otherPublished
                );

                $inserted += DB::table('checkout_cod_daily_monitor_signals')->insertOrIgnore([
                    'report_id' => (int) $report->id,
                    'observation_hmac' => $observationHmac,
                    'coverage_status' => $decision['status'],
                    'severity' => $decision['severity'],
                    'next_action' => $decision['next_action'],
                    'signal_hmac' => $this->monitorSigner->signal(
                        (string) $report->report_key,
                        (string) $report->payload_hmac,
                        $observationHmac,
                        $decision['status'], $decision['severity'], $decision['next_action']
                    ),
                    'created_at' => CarbonImmutable::now('UTC'),
                ]);
                if ($decision['requires_attention']) {
                    $attention++;
                }
            }

            $next = $reports->count() === $limit
                ? (int) $reports->last()->id : null;
            DB::table('checkout_cod_daily_monitor_cursor')
                ->where('cursor_name', 'daily_v47')
                ->update(['last_report_id' => $next, 'updated_at' => CarbonImmutable::now('UTC')]);

            return [
                'scanned' => $reports->count(),
                'signals_created' => $inserted,
                'requiring_attention_at_scan' => $attention,
                'next_before_id' => $next,
                'customer_payment_confirmed' => false,
            ];
        }, 3);
    }

    /** Paginated latest scanned state per report (not a guaranteed live status). */
    public function listing(string $scope = 'attention', ?int $beforeId = null, int $limit = 20): array
    {
        $this->actor(self::VIEW_ABILITY);
        if (!in_array($scope, ['all', 'attention', 'unacknowledged'], true)
            || ($beforeId !== null && $beforeId < 1) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid COD monitoring filters');
        }

        $q = DB::table('checkout_cod_daily_monitor_signals as s')
            ->join('checkout_cod_daily_reports as r', 'r.id', '=', 's.report_id')
            ->leftJoin('checkout_cod_daily_monitor_receipts as a', 'a.signal_id', '=', 's.id')
            ->whereRaw('s.id = (SELECT MAX(x.id) FROM checkout_cod_daily_monitor_signals x WHERE x.report_id = s.report_id)');

        if ($scope !== 'all') {
            $q->where('s.severity', '<>', 'none');
        }
        if ($scope === 'unacknowledged') {
            $q->whereNull('a.id');
        }
        if ($beforeId !== null) {
            $q->where('s.id', '<', $beforeId);
        }

        $rows = $q->orderByDesc('s.id')->limit($limit + 1)->get([
            's.*', 'r.report_key', 'r.report_date', 'r.payload_hmac',
            'a.operation_key as acknowledgement_key', 'a.actor_id as acknowledged_by',
            'a.created_at as acknowledged_at', 'a.receipt_hmac', 'a.signal_hmac as receipt_signal_hmac',
        ]);
        $items = $rows->take($limit)->map(fn (object $row): array => $this->present($row))
            ->values()->all();

        return [
            'scope' => $scope,
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['signal_id'] : null,
            'snapshot_state_only' => true,
        ];
    }

    public function history(string $reportKey, ?int $beforeId = null, int $limit = 20): array
    {
        $this->actor(self::VIEW_ABILITY);
        if (!Str::isUuid($reportKey)
            || ($beforeId !== null && $beforeId < 1) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid COD monitor history query');
        }
        $report = DB::table('checkout_cod_daily_reports')
            ->where('report_key', strtolower($reportKey))->first();
        if ($report === null) {
            throw new DomainException('COD monitoring report not found');
        }
        $q = DB::table('checkout_cod_daily_monitor_signals as s')
            ->leftJoin('checkout_cod_daily_monitor_receipts as a', 'a.signal_id', '=', 's.id')
            ->where('s.report_id', $report->id)->orderByDesc('s.id');
        if ($beforeId !== null) {
            $q->where('s.id', '<', $beforeId);
        }

        $rows = $q->limit($limit + 1)->get([
            's.*', 'a.operation_key as acknowledgement_key',
            'a.actor_id as acknowledged_by', 'a.created_at as acknowledged_at', 'a.receipt_hmac',
            'a.signal_hmac as receipt_signal_hmac',
        ]);
        $items = $rows->take($limit)->map(function (object $r) use ($report): array {
            $r->report_key = $report->report_key;
            $r->report_date = $report->report_date;
            $r->payload_hmac = $report->payload_hmac;
            return $this->present($r);
        })->values()->all();

        return [
            'report_key' => (string) $report->report_key,
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['signal_id'] : null,
        ];
    }

    /** Acknowledgement is receipt only, never evidence approval or payment confirmation. */
    public function acknowledge(int $signalId, string $operationKey): array
    {
        $actor = $this->actor(self::ACK_ABILITY);
        if ($signalId < 1 || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD monitoring acknowledgement');
        }
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use ($signalId, $operationKey, $actor): array {
            $signal = DB::table('checkout_cod_daily_monitor_signals as s')
                ->join('checkout_cod_daily_reports as r', 'r.id', '=', 's.report_id')
                ->where('s.id', $signalId)
                ->select(['s.*', 'r.report_key', 'r.report_date', 'r.payload_hmac'])
                ->first();
            if ($signal === null) {
                throw new DomainException('COD monitoring signal not found');
            }
            $this->assertSignalIntegrity($signal);
            if ($signal->severity === 'none') {
                throw new DomainException('COD monitoring clean state does not need acknowledgement');
            }

            // Report lock serializes receipt attempts for the same report.
            DB::table('checkout_cod_daily_reports')->where('id', $signal->report_id)
                ->lockForUpdate()->first(['id']);

            $prior = DB::table('checkout_cod_daily_monitor_receipts')
                ->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if ((int) $prior->signal_id !== $signalId || (int) $prior->actor_id !== $actor) {
                    throw new DomainException('COD monitoring operation key reused for another intent');
                }
                $this->assertReceiptIntegrity($prior, $signal);
                return $this->ackResult($prior, true);
            }

            $existing = DB::table('checkout_cod_daily_monitor_receipts')
                ->where('signal_id', $signalId)->first();
            if ($existing !== null) {
                throw new DomainException('COD monitoring signal already acknowledged');
            }

            $latestId = (int) DB::table('checkout_cod_daily_monitor_signals')
                ->where('report_id', $signal->report_id)->max('id');
            if ($latestId !== $signalId) {
                throw new DomainException('COD monitoring signal superseded by newer scan');
            }

            $receiptHmac = $this->monitorSigner->receipt(
                (string) $signal->signal_hmac, $operationKey, $actor
            );

            $id = (int) DB::table('checkout_cod_daily_monitor_receipts')->insertGetId([
                'signal_id' => $signalId,
                'operation_key' => $operationKey,
                'actor_id' => $actor,
                'signal_hmac' => (string) $signal->signal_hmac,
                'receipt_hmac' => $receiptHmac,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
            $receipt = DB::table('checkout_cod_daily_monitor_receipts')->where('id', $id)->first();
            return $this->ackResult($receipt, false);
        }, 3);
    }

    private function present(object $row): array
    {
        $this->assertSignalIntegrity($row);
        if ($row->acknowledgement_key !== null) {
            $this->assertReceiptIntegrity($row, $row);
        }
        return [
            'signal_id' => (int) $row->id,
            'report_key' => (string) $row->report_key,
            'report_date' => (string) $row->report_date,
            'coverage_status' => (string) $row->coverage_status,
            'severity' => (string) $row->severity,
            'next_action' => (string) $row->next_action,
            'observation_hmac' => (string) $row->observation_hmac,
            'observed_at' => (string) $row->created_at,
            'acknowledged_by' => $row->acknowledged_by === null ? null : (int) $row->acknowledged_by,
            'acknowledged_at' => $row->acknowledged_at === null ? null : (string) $row->acknowledged_at,
            'customer_payment_confirmed' => false,
        ];
    }

    private function assertSignalIntegrity(object $signal): void
    {
        $wanted = $this->monitorSigner->signal(
            (string) $signal->report_key, (string) $signal->payload_hmac,
            (string) $signal->observation_hmac, (string) $signal->coverage_status,
            (string) $signal->severity, (string) $signal->next_action
        );
        if (!hash_equals((string) $signal->signal_hmac, $wanted)) {
            throw new DomainException('COD monitoring signal signature invalid');
        }
    }

    private function assertReceiptIntegrity(object $receipt, object $signal): void
    {
        $operation = (string) ($receipt->operation_key ?? $receipt->acknowledgement_key);
        $actor = (int) ($receipt->actor_id ?? $receipt->acknowledged_by);
        $wanted = $this->monitorSigner->receipt((string) $signal->signal_hmac, $operation, $actor);
        if (!hash_equals((string) $receipt->receipt_hmac, $wanted)
            || ($receipt->receipt_signal_hmac ?? $receipt->signal_hmac) !== $signal->signal_hmac) {
            throw new DomainException('COD monitoring acknowledgement signature invalid');
        }
    }

    private function ackResult(object $receipt, bool $replay): array
    {
        return [
            'signal_id' => (int) $receipt->signal_id,
            'acknowledged_by' => (int) $receipt->actor_id,
            'acknowledged_at' => (string) $receipt->created_at,
            'idempotent_replay' => $replay,
            'financial_evidence_approved' => false,
            'orders_payment_status_changed' => false,
        ];
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows(CheckoutCodDailyReportService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('COD monitoring permission denied');
        }
        return (int) $id;
    }
}
