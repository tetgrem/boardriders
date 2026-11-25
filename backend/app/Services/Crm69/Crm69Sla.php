<?php

declare(strict_types=1);

namespace App\Services\Crm69;

use App\Models\User;
use App\Services\Crm66\Crm66Tasks;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/** CRM-only SLA and assignment. Never mutates orders, payment, delivery or fiscal state. */
final class Crm69Sla
{
    public function __construct(
        private readonly Crm66Tasks $tasks,
        private readonly CheckoutOrderReturnSigner $signer,
    ) {}

    public function overview(array $filters): array
    {
        $actor = $this->actor();
        $filter = (string) ($filters['filter'] ?? 'all');
        $limit = (int) ($filters['limit'] ?? 20);
        $cursor = (int) ($filters['before_id'] ?? 0);
        if (!in_array($filter, ['all', 'mine', 'overdue', 'soon', 'unplanned'], true)
            || $limit < 1 || $limit > (int) config('crm69.max_page_size', 30) || $cursor < 0) {
            throw new DomainException('Invalid SLA filter');
        }
        $now = CarbonImmutable::now('UTC');
        $soon = $now->addHours((int) config('crm69.sla_soon_hours', 24));
        $base = fn (): Builder => DB::table('checkout_crm_tasks66 as t')
            ->leftJoin('checkout_crm_task_sla69 as s', 's.task_id', '=', 't.id');

        $counts = $base()->selectRaw(<<<'SQL'
            COUNT(*) FILTER (WHERE t.status <> 'resolved') AS active,
            COUNT(*) FILTER (WHERE t.status <> 'resolved' AND s.due_at < ?) AS overdue,
            COUNT(*) FILTER (WHERE t.status <> 'resolved' AND s.due_at >= ? AND s.due_at <= ?) AS soon,
            COUNT(*) FILTER (WHERE t.status <> 'resolved' AND s.due_at IS NULL) AS unplanned
        SQL, [$now, $now, $soon])->first();

        $q = $base()->join('orders as o', 'o.id', '=', 't.order_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->select(['t.*', 'o.order_key', 'u.name as assignee_name',
                's.due_at as sla_due_at', 's.revision as sla_revision']);
        $q = match ($filter) {
            'mine' => $q->where('t.assigned_to', $actor)->where('t.status', '<>', 'resolved'),
            'overdue' => $q->where('t.status', '<>', 'resolved')->where('s.due_at', '<', $now),
            'soon' => $q->where('t.status', '<>', 'resolved')
                ->whereBetween('s.due_at', [$now, $soon]),
            'unplanned' => $q->where('t.status', '<>', 'resolved')->whereNull('s.due_at'),
            default => $q,
        };
        if ($cursor > 0) $q->where('t.id', '<', $cursor);
        $rows = $q->orderByDesc('t.id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $visible = $rows->take($limit)->values()->all();
        $verified = $this->tasks->verifiedBatch($visible);
        $items = [];
        foreach ($visible as $i => $row) {
            $this->verifySla((int) $row->id, $row->sla_revision, $row->sla_due_at);
            $due = $this->iso($row->sla_due_at);
            $items[] = [
                ...$verified[$i], 'order_key' => (string) $row->order_key,
                'assignee_name' => $row->assignee_name === null ? null : (string) $row->assignee_name,
                'due_at' => $due, 'sla_revision' => (int) ($row->sla_revision ?? 0),
                'is_overdue' => $due !== null && $row->status !== 'resolved' && $due < $now->format('Y-m-d\TH:i:s.u\Z'),
            ];
        }
        return [
            'items' => $items,
            'next_before_id' => $more && $items !== [] ? (int) end($visible)->id : null,
            'summary' => [
                'active' => (int) ($counts->active ?? 0), 'overdue' => (int) ($counts->overdue ?? 0),
                'soon' => (int) ($counts->soon ?? 0), 'unplanned' => (int) ($counts->unplanned ?? 0),
            ],
            'workload' => $this->workload(),
            'read_only' => true,
        ];
    }

    public function assignees(): array
    {
        $this->actor();
        return DB::table('checkout_crm_staff65 as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.active', true)->whereIn('s.role', ['owner', 'manager', 'support'])
            ->orderBy('u.name')->limit(200)
            ->get(['s.user_id', 's.role', 'u.name'])
            ->map(fn (object $r): array => [
                'user_id' => (int) $r->user_id, 'name' => (string) $r->name, 'role' => (string) $r->role,
            ])->all();
    }

    public function setDeadline(int $taskId, ?string $due, int $expected, string $key): array
    {
        $actor = $this->actor(true);
        $key = $this->uuid($key);
        if ($expected < 0 || $expected >= 2147483647) throw new DomainException('Invalid SLA revision');
        $next = $this->parseDue($due);
        return DB::transaction(function () use ($taskId, $next, $expected, $key, $actor): array {
            $task = $this->lockTask($taskId);
            if ((string) $task->status === 'resolved') throw new DomainException('Resolved task cannot have SLA changes');
            $sla = DB::table('checkout_crm_task_sla69')->where('task_id', $taskId)->lockForUpdate()->first();
            $this->verifySla($taskId, $sla?->revision, $sla?->due_at);
            $previous = DB::table('checkout_crm_sla_events69')->where('operation_key', $key)->first();
            if ($previous !== null) {
                $event = $this->verifiedDueEvent($previous);
                if ($event['task_id'] !== $taskId || $event['actor_id'] !== $actor
                    || $event['revision'] !== $expected + 1 || $event['to_due_at'] !== $next) {
                    throw new DomainException('SLA operation key reused');
                }
                return ['due_at' => $this->iso($sla?->due_at), 'revision' => (int) ($sla?->revision ?? 0), 'replayed' => true];
            }
            $currentRevision = (int) ($sla?->revision ?? 0);
            if ($currentRevision !== $expected) throw new DomainException('SLA changed. Refresh the page');
            $old = $this->iso($sla?->due_at);
            if ($old === $next) throw new DomainException('SLA deadline is unchanged');
            $revision = $currentRevision + 1;
            if ($sla === null) {
                DB::table('checkout_crm_task_sla69')->insert([
                    'task_id' => $taskId, 'due_at' => $next, 'revision' => $revision,
                    'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('checkout_crm_task_sla69')->where('task_id', $taskId)->update([
                    'due_at' => $next, 'revision' => $revision, 'updated_by' => $actor, 'updated_at' => now(),
                ]);
            }
            $event = [
                'task_id' => $taskId, 'operation_key' => $key, 'actor_id' => $actor,
                'from_due_at' => $old, 'to_due_at' => $next, 'revision' => $revision,
            ];
            DB::table('checkout_crm_sla_events69')->insert([
                ...$event, 'event_hmac' => $this->signer->digest($event), 'created_at' => now(),
            ]);
            return ['due_at' => $next, 'revision' => $revision, 'replayed' => false];
        }, 3);
    }

    public function comment(int $taskId, string $body, string $operationKey): array
    {
        $actor = $this->actor();
        $key = $this->uuid($operationKey);
        $body = trim($body);
        if (mb_strlen($body) < 2 || mb_strlen($body) > 500) throw new DomainException('Invalid CRM comment');
        return DB::transaction(function () use ($taskId, $body, $key, $actor): array {
            $this->lockTask($taskId);
            $prior = DB::table('checkout_crm_task_notes69')->where('operation_key', $key)->first();
            if ($prior !== null) {
                $old = $this->verifiedNote($prior);
                if ($old['task_id'] !== $taskId || $old['actor_id'] !== $actor || $old['body'] !== $body) {
                    throw new DomainException('CRM comment operation key reused');
                }
                return [...$old, 'replayed' => true];
            }
            $event = ['task_id' => $taskId, 'operation_key' => $key, 'actor_id' => $actor, 'body' => $body];
            DB::table('checkout_crm_task_notes69')->insert([
                ...$event, 'event_hmac' => $this->signer->digest($event), 'created_at' => now(),
            ]);
            return [...$event, 'replayed' => false];
        }, 3);
    }

    public function assign(int $taskId, ?int $target, int $expected, string $operationKey): array
    {
        $actor = $this->actor(true);
        $key = $this->uuid($operationKey);
        if ($expected < 1 || $expected >= 2147483647 || ($target !== null && $target < 1)) {
            throw new DomainException('Invalid CRM assignment');
        }
        return DB::transaction(function () use ($taskId, $target, $expected, $key, $actor): array {
            $task = $this->lockTask($taskId);
            $previous = DB::table('checkout_crm_task_events66')->where('operation_key', $key)->first();
            if ($previous !== null) {
                $old = $this->verifiedTaskEvent($previous);
                if (!in_array($old['action'], ['assigned', 'reassigned', 'unassigned'], true)
                    || $old['task_id'] !== $taskId || $old['actor_id'] !== $actor
                    || $old['version'] !== $expected + 1 || $old['assigned_to'] !== $target) {
                    throw new DomainException('CRM assignment operation key reused');
                }
                return ['task' => $this->tasks->verifiedBatch([$task])[0], 'replayed' => true];
            }
            if ((int) $task->version !== $expected) throw new DomainException('CRM task changed. Refresh the page');
            if ((string) $task->status === 'resolved') throw new DomainException('Cannot assign resolved task');
            $oldAssignee = $task->assigned_to === null ? null : (int) $task->assigned_to;
            if ($oldAssignee === $target) throw new DomainException('Task assignee is unchanged');
            if ($target !== null) {
                $staff = DB::table('checkout_crm_staff65')->where('user_id', $target)
                    ->lockForUpdate()->first(['active', 'role']);
                if ($staff === null || !(bool) $staff->active
                    || !in_array((string) $staff->role, ['owner', 'manager', 'support'], true)) {
                    throw new DomainException('Only active task employees can be assigned');
                }
            }
            $version = $expected + 1;
            DB::table('checkout_crm_tasks66')->where('id', $taskId)->update([
                'assigned_to' => $target, 'status' => 'open',
                'version' => $version, 'updated_at' => now(),
            ]);
            $event = [
                'task_id' => $taskId, 'order_id' => (int) $task->order_id,
                'operation_key' => $key,
                'action' => $target === null ? 'unassigned' : ($oldAssignee === null ? 'assigned' : 'reassigned'),
                'version' => $version, 'title' => (string) $task->title,
                'priority' => (string) $task->priority, 'status' => 'open',
                'created_by' => (int) $task->created_by, 'assigned_to' => $target,
                'actor_id' => $actor,
            ];
            DB::table('checkout_crm_task_events66')->insert([
                ...$event, 'event_hmac' => $this->signer->digest($event), 'created_at' => now(),
            ]);
            return ['task' => [
                'task_id' => $taskId, 'assigned_to' => $target, 'status' => 'open', 'version' => $version,
            ], 'replayed' => false];
        }, 3);
    }

    public function history(int $taskId): array
    {
        $this->actor();
        $this->lockTask($taskId);
        $sla = DB::table('checkout_crm_task_sla69')->where('task_id', $taskId)->first();
        $this->verifySla($taskId, $sla?->revision, $sla?->due_at);
        return [
            'deadlines' => DB::table('checkout_crm_sla_events69')->where('task_id', $taskId)
                ->orderByDesc('revision')->limit(50)->get()
                ->map(fn (object $event): array => [
                    ...$this->verifiedDueEvent($event), 'created_at' => (string) $event->created_at,
                ])->all(),
            'comments' => DB::table('checkout_crm_task_notes69')->where('task_id', $taskId)
                ->orderByDesc('id')->limit(50)->get()
                ->map(fn (object $event): array => [
                    ...$this->verifiedNote($event), 'created_at' => (string) $event->created_at,
                ])->all(),
        ];
    }

    private function workload(): array
    {
        return DB::table('checkout_crm_staff65 as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('checkout_crm_tasks66 as t', function ($join): void {
                $join->on('t.assigned_to', '=', 's.user_id')->where('t.status', '<>', 'resolved');
            })
            ->where('s.active', true)->whereIn('s.role', ['owner', 'manager', 'support'])
            ->groupBy('s.user_id', 'u.name')->orderBy('u.name')->limit(100)
            ->get(['s.user_id', 'u.name', DB::raw('COUNT(t.id) AS active_tasks')])
            ->map(fn (object $row): array => [
                'user_id' => (int) $row->user_id, 'name' => (string) $row->name,
                'active_tasks' => (int) $row->active_tasks,
            ])->all();
    }

    private function lockTask(int $taskId): object
    {
        if ($taskId < 1) throw new DomainException('Invalid CRM task id');
        $task = DB::table('checkout_crm_tasks66')->where('id', $taskId)->lockForUpdate()->first();
        if ($task === null) throw new DomainException('CRM task not found');
        $this->tasks->verifiedBatch([$task]);
        return $task;
    }

    private function verifySla(int $taskId, mixed $revision, mixed $due): void
    {
        $last = DB::table('checkout_crm_sla_events69')->where('task_id', $taskId)
            ->orderByDesc('revision')->first();
        if ($revision === null) {
            if ($last !== null) throw new RuntimeException('CRM SLA projection missing');
            return;
        }
        if ($last === null) throw new RuntimeException('CRM SLA audit missing');
        $event = $this->verifiedDueEvent($last);
        if ($event['revision'] !== (int) $revision || $event['to_due_at'] !== $this->iso($due)) {
            throw new RuntimeException('CRM SLA projection differs from audit');
        }
    }

    private function verifiedDueEvent(object $row): array
    {
        $event = [
            'task_id' => (int) $row->task_id,
            'operation_key' => (string) $row->operation_key,
            'actor_id' => (int) $row->actor_id,
            'from_due_at' => $this->iso($row->from_due_at),
            'to_due_at' => $this->iso($row->to_due_at),
            'revision' => (int) $row->revision,
        ];
        $this->verifySignature($event, (string) $row->event_hmac);
        return $event;
    }

    private function verifiedNote(object $row): array
    {
        $event = [
            'task_id' => (int) $row->task_id,
            'operation_key' => (string) $row->operation_key,
            'actor_id' => (int) $row->actor_id,
            'body' => (string) $row->body,
        ];
        $this->verifySignature($event, (string) $row->event_hmac);
        return $event;
    }

    private function verifiedTaskEvent(object $row): array
    {
        $event = [
            'task_id' => (int) $row->task_id, 'order_id' => (int) $row->order_id,
            'operation_key' => (string) $row->operation_key,
            'action' => (string) $row->action, 'version' => (int) $row->version,
            'title' => (string) $row->title, 'priority' => (string) $row->priority,
            'status' => (string) $row->status, 'created_by' => (int) $row->created_by,
            'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to,
            'actor_id' => (int) $row->actor_id,
        ];
        $this->verifySignature($event, (string) $row->event_hmac);
        return $event;
    }

    private function verifySignature(array $event, string $signature): void
    {
        if (!hash_equals($signature, $this->signer->digest($event))) {
            throw new RuntimeException('CRM69 signed audit integrity check failed');
        }
    }

    private function iso(mixed $date): ?string
    {
        if ($date === null) return null;
        return CarbonImmutable::parse((string) $date)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function parseDue(?string $date): ?string
    {
        if ($date === null) return null;
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date, 'UTC');
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $date) {
            throw new DomainException('Invalid ISO UTC deadline');
        }
        $now = CarbonImmutable::now('UTC');
        if ($parsed->lessThanOrEqualTo($now) || $parsed->greaterThan($now->addYear())) {
            throw new DomainException('Deadline must be in the next 12 months');
        }
        return $parsed->format('Y-m-d\TH:i:s.u\Z');
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) throw new DomainException('Invalid CRM operation UUID');
        return strtolower($value);
    }

    private function actor(bool $supervisor = false): int
    {
        if (config('crm69.enabled') !== true || config('crm66.enabled') !== true
            || config('crm65.enabled') !== true) abort(503, 'CRM SLA disabled');
        $user = Auth::user();
        if (!$user instanceof User) abort(401);
        Gate::forUser($user)->authorize($supervisor ? 'crm69.sla.manage' : 'crm66.tasks.manage');
        return (int) $user->getKey();
    }
}
