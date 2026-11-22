<?php

declare(strict_types=1);

namespace App\Services\Crm66;

use App\Models\User;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/** Internal CRM workflow only. Never changes order, payment, RMA, carrier or fiscal state. */
final class Crm66Tasks
{
    private const VIEW = 'crm66.tasks.view';
    private const MANAGE = 'crm66.tasks.manage';

    public function __construct(private readonly CheckoutOrderReturnSigner $signer) {}

    public function list(string $orderKey): array
    {
        $this->actor(self::VIEW);
        $orderId = $this->orderId($orderKey);
        $max = (int) config('crm66.max_tasks_per_order', 100);
        $max = max(1, min(100, $max));
        $query = DB::table('checkout_crm_tasks66')->where('order_id', $orderId);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')->limit($max)->get()
            ->map(fn (object $task): array => $this->verified($task))->all();

        return ['items' => $items, 'total' => $total, 'truncated' => $total > count($items)];
    }

    public function create(string $orderKey, string $operationKey, string $title, string $priority): array
    {
        $actor = $this->actor(self::MANAGE);
        $key = $this->uuid($operationKey);
        $title = trim($title);
        if (mb_strlen($title) < 4 || mb_strlen($title) > 160
            || !in_array($priority, ['low','normal','high','critical'], true)) {
            throw new DomainException('Invalid CRM task');
        }

        return DB::transaction(function () use ($orderKey, $key, $title, $priority, $actor): array {
            // Serialize task creation per order. An operation key is globally unique.
            $orderId = $this->orderId($orderKey, true);
            $previous = DB::table('checkout_crm_task_events66')
                ->where('operation_key', $key)->first();
            if ($previous !== null) {
                $old = $this->verifiedEvent($previous);
                if ($old['action'] !== 'created' || $old['order_id'] !== $orderId
                    || $old['title'] !== $title || $old['priority'] !== $priority
                    || $old['actor_id'] !== $actor) {
                    throw new DomainException('CRM operation key reused with different content');
                }
                return $this->result($old, true);
            }

            $id = (int) DB::table('checkout_crm_tasks66')->insertGetId([
                'order_id' => $orderId, 'title' => $title, 'priority' => $priority,
                'status' => 'open', 'created_by' => $actor, 'assigned_to' => null,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $event = [
                'task_id' => $id, 'order_id' => $orderId, 'operation_key' => $key,
                'action' => 'created', 'version' => 1, 'title' => $title,
                'priority' => $priority, 'status' => 'open', 'created_by' => $actor,
                'assigned_to' => null, 'actor_id' => $actor,
            ];
            $this->insertEvent($event);
            return $this->result($event);
        }, 3);
    }

    public function act(string $orderKey, int $taskId, string $operationKey, string $action, int $expectedVersion): array
    {
        $actor = $this->actor(self::MANAGE);
        $key = $this->uuid($operationKey);
        if ($taskId < 1 || $expectedVersion < 1
            || !in_array($action, ['claim','release','start','resolve','reopen'], true)) {
            throw new DomainException('Invalid CRM task transition');
        }

        return DB::transaction(function () use ($orderKey, $taskId, $key, $action, $expectedVersion, $actor): array {
            $orderId = $this->orderId($orderKey);
            $task = DB::table('checkout_crm_tasks66')
                ->where('id', $taskId)->where('order_id', $orderId)
                ->lockForUpdate()->first();
            if ($task === null) {
                throw new DomainException('CRM task not found');
            }
            $this->verified($task);

            $previous = DB::table('checkout_crm_task_events66')
                ->where('operation_key', $key)->first();
            $eventAction = [
                'claim' => 'claimed', 'release' => 'released', 'start' => 'started',
                'resolve' => 'resolved', 'reopen' => 'reopened',
            ][$action];
            if ($previous !== null) {
                $old = $this->verifiedEvent($previous);
                if ($old['task_id'] !== $taskId || $old['order_id'] !== $orderId
                    || $old['action'] !== $eventAction || $old['actor_id'] !== $actor
                    || $old['version'] !== $expectedVersion + 1) {
                    throw new DomainException('CRM operation key reused with different content');
                }
                return $this->result($old, true);
            }

            if ((int) $task->version !== $expectedVersion) {
                throw new DomainException('CRM task changed. Refresh the page');
            }
            $status = (string) $task->status;
            $assignee = $task->assigned_to === null ? null : (int) $task->assigned_to;

            switch ($action) {
                case 'claim':
                    if ($status !== 'open' || $assignee !== null) {
                        throw new DomainException('Only an unassigned open task can be claimed');
                    }
                    $assignee = $actor;
                    break;
                case 'release':
                    if ($status !== 'open' || $assignee !== $actor) {
                        throw new DomainException('Only your open task can be released');
                    }
                    $assignee = null;
                    break;
                case 'start':
                    if ($status !== 'open' || $assignee !== $actor) {
                        throw new DomainException('Claim the task before starting');
                    }
                    $status = 'in_progress';
                    break;
                case 'resolve':
                    if ($status !== 'in_progress' || $assignee !== $actor) {
                        throw new DomainException('Only the assigned employee can resolve an active task');
                    }
                    $status = 'resolved';
                    break;
                case 'reopen':
                    if ($status !== 'resolved') {
                        throw new DomainException('Only resolved tasks can be reopened');
                    }
                    $status = 'open';
                    $assignee = null;
                    break;
            }

            $version = $expectedVersion + 1;
            DB::table('checkout_crm_tasks66')->where('id', $taskId)->update([
                'status' => $status, 'assigned_to' => $assignee,
                'version' => $version, 'updated_at' => now(),
            ]);
            $event = [
                'task_id' => $taskId, 'order_id' => $orderId, 'operation_key' => $key,
                'action' => $eventAction, 'version' => $version,
                'title' => (string) $task->title, 'priority' => (string) $task->priority,
                'status' => $status, 'created_by' => (int) $task->created_by,
                'assigned_to' => $assignee, 'actor_id' => $actor,
            ];
            $this->insertEvent($event);
            return $this->result($event);
        }, 3);
    }

    public function history(string $orderKey, int $taskId): array
    {
        $this->actor(self::VIEW);
        $orderId = $this->orderId($orderKey);
        $task = DB::table('checkout_crm_tasks66')->where('id', $taskId)
            ->where('order_id', $orderId)->first();
        if ($task === null) throw new DomainException('CRM task not found');
        $this->verified($task);
        return DB::table('checkout_crm_task_events66')->where('task_id', $taskId)
            ->orderByDesc('version')->limit(100)->get()->reverse()->values()
            ->map(fn (object $event): array => [
                ...$this->result($this->verifiedEvent($event)),
                'created_at' => (string) $event->created_at,
            ])->all();
    }

    private function actor(string $ability): int
    {
        if (config('crm66.enabled') !== true || config('crm65.enabled') !== true) {
            abort(503, 'CRM task management disabled');
        }
        $user = Auth::user();
        if (!$user instanceof User) abort(401);
        Gate::forUser($user)->authorize($ability);
        return (int) $user->getKey();
    }

    private function orderId(string $orderKey, bool $lock = false): int
    {
        $key = $this->uuid($orderKey);
        $query = DB::table('orders')->where('order_key', $key);
        $row = ($lock ? $query->lockForUpdate() : $query)->first(['id']);
        if ($row === null) throw new DomainException('CRM order not found');
        return (int) $row->id;
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) throw new DomainException('Invalid CRM UUID');
        return strtolower($value);
    }

    private function insertEvent(array $event): void
    {
        DB::table('checkout_crm_task_events66')->insert([
            ...$event, 'event_hmac' => $this->signer->digest($event), 'created_at' => now(),
        ]);
    }

    private function verified(object $task): array
    {
        $last = DB::table('checkout_crm_task_events66')
            ->where('task_id', $task->id)->orderByDesc('version')->first();
        if ($last === null) throw new RuntimeException('CRM task is missing its history');
        $event = $this->verifiedEvent($last);
        foreach (['order_id', 'title', 'priority', 'status', 'created_by', 'assigned_to', 'version'] as $field) {
            $value = in_array($field, ['order_id','created_by','version'], true)
                ? (int) $task->$field
                : ($field === 'assigned_to'
                    ? ($task->$field === null ? null : (int) $task->$field)
                    : (string) $task->$field);
            if ($event[$field] !== $value) {
                throw new RuntimeException('CRM task projection does not match signed history');
            }
        }
        return $this->result($event);
    }

    private function verifiedEvent(object $row): array
    {
        $event = [
            'task_id' => (int) $row->task_id,
            'order_id' => (int) $row->order_id,
            'operation_key' => (string) $row->operation_key,
            'action' => (string) $row->action,
            'version' => (int) $row->version,
            'title' => (string) $row->title,
            'priority' => (string) $row->priority,
            'status' => (string) $row->status,
            'created_by' => (int) $row->created_by,
            'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to,
            'actor_id' => (int) $row->actor_id,
        ];
        if (!hash_equals((string) $row->event_hmac, $this->signer->digest($event))) {
            throw new RuntimeException('CRM task event signature mismatch');
        }
        return $event;
    }

    private function result(array $event, bool $replayed = false): array
    {
        return [
            'task_id' => $event['task_id'], 'title' => $event['title'],
            'priority' => $event['priority'], 'status' => $event['status'],
            'assigned_to' => $event['assigned_to'], 'created_by' => $event['created_by'],
            'version' => $event['version'], 'action' => $event['action'],
            'actor_id' => $event['actor_id'], 'replayed' => $replayed,
        ];
    }
}
