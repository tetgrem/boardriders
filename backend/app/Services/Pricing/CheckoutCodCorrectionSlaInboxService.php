<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Role-scoped CRM read model and append-only acknowledgement receipts. */
final class CheckoutCodCorrectionSlaInboxService
{
    public const VIEW_ABILITY = 'cod.operations.correction_sla.view';
    public const ACK_ABILITY = 'cod.operations.correction_sla.acknowledge';
    public const REVIEW_TEAM_ABILITY = 'cod.operations.correction_sla.team.review';
    public const PUBLICATION_TEAM_ABILITY = 'cod.operations.correction_sla.team.publication';
    public const MANAGEMENT_ABILITY = 'cod.operations.correction_sla.team.management';

    public function __construct(private readonly CheckoutCodCorrectionSlaSigner $signer)
    {
    }

    /** @return array<string,mixed> */
    public function listing(string $scope = 'unacknowledged', ?int $beforeId = null, int $limit = 20): array
    {
        $this->actor(self::VIEW_ABILITY);
        $this->validatePage($beforeId, $limit);
        if (!in_array($scope, ['all', 'unacknowledged', 'critical'], true)) {
            throw new InvalidArgumentException('Invalid COD correction SLA inbox scope');
        }
        $roles = $this->audiences();
        if ($roles === []) {
            return ['scope' => $scope, 'items' => [], 'next_before_id' => null];
        }
        $query = $this->currentQuery()->whereIn('a.target_role', $roles);
        if ($scope === 'unacknowledged') {
            $query->whereNull('receipt.id');
        } elseif ($scope === 'critical') {
            $query->where('a.severity', 'critical');
        }
        if ($beforeId !== null) {
            $query->where('a.id', '<', $beforeId);
        }
        $rows = $query->orderByDesc('a.id')->limit($limit + 1)->get($this->columns());
        $items = $rows->take($limit)->map(fn (object $row): array => $this->present($row))
            ->values()->all();
        return [
            'scope' => $scope,
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['alert_id'] : null,
            'last_scanned_state_not_live_financial_approval' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $this->actor(self::VIEW_ABILITY);
        $roles = $this->audiences();
        if ($roles === []) {
            return [
                'active_alerts' => 0,
                'unacknowledged' => 0,
                'critical' => 0,
                'high' => 0,
                'warning' => 0,
                'management_escalations' => 0,
                'last_scanned_state' => true,
            ];
        }
        $stats = $this->currentQuery()->whereIn('a.target_role', $roles)
            ->selectRaw(<<<'SQL'
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE receipt.id IS NULL) AS unread,
                COUNT(*) FILTER (WHERE a.severity = 'critical') AS critical,
                COUNT(*) FILTER (WHERE a.severity = 'high') AS high,
                COUNT(*) FILTER (WHERE a.severity = 'warning') AS warning,
                COUNT(*) FILTER (WHERE a.target_role = 'finance_management') AS management
            SQL)->first();
        return [
            'active_alerts' => (int) $stats->total,
            'unacknowledged' => (int) $stats->unread,
            'critical' => (int) $stats->critical,
            'high' => (int) $stats->high,
            'warning' => (int) $stats->warning,
            'management_escalations' => (int) $stats->management,
            'last_scanned_state' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function history(string $correctionKey, ?int $beforeId = null, int $limit = 20): array
    {
        $this->actor(self::VIEW_ABILITY);
        $this->validatePage($beforeId, $limit);
        if (!Str::isUuid($correctionKey)) {
            throw new InvalidArgumentException('Invalid COD correction UUID');
        }
        $roles = $this->audiences();
        if ($roles === []) {
            return ['items' => [], 'next_before_id' => null];
        }
        $query = $this->allQuery()
            ->where('c.correction_key', strtolower($correctionKey))
            ->whereIn('a.target_role', $roles);
        if ($beforeId !== null) {
            $query->where('a.id', '<', $beforeId);
        }
        $rows = $query->orderByDesc('a.id')->limit($limit + 1)->get($this->columns());
        $items = $rows->take($limit)->map(fn (object $row): array => $this->present($row))
            ->values()->all();
        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['alert_id'] : null,
        ];
    }

    /** @return array<string,mixed> */
    public function acknowledge(int $alertId, string $operationKey): array
    {
        $actor = $this->actor(self::ACK_ABILITY);
        if ($alertId < 1 || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD correction SLA acknowledgement');
        }
        $operationKey = strtolower($operationKey);
        $roles = $this->audiences();

        return DB::transaction(function () use ($actor, $alertId, $operationKey, $roles): array {
            $alert = $this->allQuery()->where('a.id', $alertId)
                ->first($this->columns());
            if ($alert === null) {
                throw new DomainException('COD correction SLA alert not found');
            }
            if (!in_array((string) $alert->target_role, $roles, true)) {
                throw new AuthorizationException('COD correction SLA target role denied');
            }
            $this->assertAlert($alert);
            DB::table('checkout_cod_daily_corrections')
                ->where('id', $alert->correction_id)->lockForUpdate()->first(['id']);

            $prior = DB::table('checkout_cod_correction_sla_receipts')
                ->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if ((int) $prior->alert_id !== $alertId || (int) $prior->actor_id !== $actor) {
                    throw new DomainException('COD correction SLA UUID used for different intent');
                }
                $this->assertReceipt(
                    (string) $prior->alert_hmac, (string) $prior->receipt_hmac,
                    (string) $prior->operation_key, (int) $prior->actor_id,
                    (string) $alert->alert_hmac
                );
                return $this->ackResult($prior, true);
            }
            if (DB::table('checkout_cod_correction_sla_receipts')
                ->where('alert_id', $alertId)->exists()) {
                throw new DomainException('COD correction SLA alert already acknowledged');
            }
            $latestPhase = (int) DB::table('checkout_cod_daily_correction_events')
                ->where('correction_id', $alert->correction_id)
                ->whereIn('event_kind', ['proposed', 'approved', 'rejected', 'published'])
                ->max('id');
            $latestAlert = (int) DB::table('checkout_cod_correction_sla_alerts')
                ->where('correction_id', $alert->correction_id)->max('id');
            if ($latestPhase !== (int) $alert->phase_event_id || $latestAlert !== $alertId) {
                throw new DomainException('COD correction SLA alert superseded');
            }
            $id = (int) DB::table('checkout_cod_correction_sla_receipts')->insertGetId([
                'alert_id' => $alertId,
                'operation_key' => $operationKey,
                'actor_id' => $actor,
                'alert_hmac' => (string) $alert->alert_hmac,
                'receipt_hmac' => $this->signer->receipt((string) $alert->alert_hmac, $operationKey, $actor),
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
            $receipt = DB::table('checkout_cod_correction_sla_receipts')->where('id', $id)->first();
            return $this->ackResult($receipt, false);
        }, 3);
    }

    private function allQuery(): Builder
    {
        return DB::table('checkout_cod_correction_sla_alerts as a')
            ->join('checkout_cod_daily_corrections as c', 'c.id', '=', 'a.correction_id')
            ->join('checkout_cod_daily_reports as d', 'd.id', '=', 'c.report_id')
            ->leftJoin('checkout_cod_correction_sla_receipts as receipt', 'receipt.alert_id', '=', 'a.id');
    }

    private function currentQuery(): Builder
    {
        return $this->allQuery()
            ->whereRaw('a.id = (SELECT MAX(x.id) FROM checkout_cod_correction_sla_alerts x WHERE x.correction_id = a.correction_id)')
            ->whereRaw("a.phase_event_id = (SELECT MAX(e.id) FROM checkout_cod_daily_correction_events e WHERE e.correction_id = a.correction_id AND e.event_kind IN ('proposed','approved','rejected','published'))");
    }

    private function columns(): array
    {
        return [
            'a.*', 'c.correction_key', 'c.appendix_hmac', 'd.report_key', 'd.report_date',
            'receipt.id as receipt_id', 'receipt.operation_key as receipt_operation_key',
            'receipt.actor_id as receipt_actor_id', 'receipt.alert_hmac as receipt_alert_hmac',
            'receipt.receipt_hmac', 'receipt.created_at as receipt_created_at',
        ];
    }

    private function present(object $row): array
    {
        $this->assertAlert($row);
        if ($row->receipt_id !== null) {
            $this->assertReceipt(
                (string) $row->receipt_alert_hmac, (string) $row->receipt_hmac,
                (string) $row->receipt_operation_key, (int) $row->receipt_actor_id,
                (string) $row->alert_hmac
            );
        }
        return [
            'alert_id' => (int) $row->id,
            'correction_key' => (string) $row->correction_key,
            'report_key' => (string) $row->report_key,
            'report_date' => (string) $row->report_date,
            'phase' => (string) $row->phase,
            'stage' => (string) $row->stage,
            'severity' => (string) $row->severity,
            'target_role' => (string) $row->target_role,
            'due_at' => (string) $row->due_at,
            'observed_at' => (string) $row->created_at,
            'acknowledged_by' => $row->receipt_actor_id === null ? null : (int) $row->receipt_actor_id,
            'acknowledged_at' => $row->receipt_created_at === null ? null : (string) $row->receipt_created_at,
            'financial_approval_performed' => false,
        ];
    }

    private function assertAlert(object $row): void
    {
        $expected = $this->signer->alert(
            (string) $row->correction_key, (string) $row->appendix_hmac,
            (int) $row->phase_event_id, (string) $row->phase,
            (string) $row->stage, (string) $row->severity,
            (string) $row->target_role, $this->signer->stamp((string) $row->due_at)
        );
        if (!hash_equals((string) $row->alert_hmac, $expected)) {
            throw new DomainException('COD correction SLA alert integrity invalid');
        }
    }

    private function assertReceipt(
        string $storedAlertHmac, string $receiptHmac,
        string $operationKey, int $actorId, string $expectedAlertHmac
    ): void {
        $expected = $this->signer->receipt($expectedAlertHmac, $operationKey, $actorId);
        if (!hash_equals($expectedAlertHmac, $storedAlertHmac)
            || !hash_equals($expected, $receiptHmac)) {
            throw new DomainException('COD correction SLA receipt integrity invalid');
        }
    }

    private function ackResult(object $receipt, bool $replay): array
    {
        return [
            'alert_id' => (int) $receipt->alert_id,
            'acknowledged_by' => (int) $receipt->actor_id,
            'acknowledged_at' => (string) $receipt->created_at,
            'idempotent_replay' => $replay,
            'financial_approval_performed' => false,
            'orders_payment_status_changed' => false,
        ];
    }

    private function audiences(): array
    {
        $user = Auth::user();
        $roles = [];
        foreach ([
                     'finance_review' => self::REVIEW_TEAM_ABILITY,
                     'finance_publication' => self::PUBLICATION_TEAM_ABILITY,
                     'finance_management' => self::MANAGEMENT_ABILITY,
                 ] as $role => $permission) {
            if (Gate::forUser($user)->allows($permission)) {
                $roles[] = $role;
            }
        }
        return $roles;
    }

    private function actor(string $permission): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows(CheckoutCodDailyReportService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows(self::VIEW_ABILITY)
            || !Gate::forUser($user)->allows($permission)) {
            throw new AuthorizationException('COD correction SLA CRM access denied');
        }
        return (int) $id;
    }

    private function validatePage(?int $beforeId, int $limit): void
    {
        if (($beforeId !== null && $beforeId < 1) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid COD correction SLA pagination');
        }
    }
}
