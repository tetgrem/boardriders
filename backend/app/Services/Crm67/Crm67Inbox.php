<?php

declare(strict_types=1);

namespace App\Services\Crm67;

use App\Models\User;
use App\Services\Crm66\Crm66Tasks;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Read-only queue over signed CRM66 task events. Does not mutate any order state. */
final class Crm67Inbox
{
    public function __construct(private readonly Crm66Tasks $tasks) {}

    public function page(array $filters): array
    {
        $actorId = $this->actor();
        $scope = (string) ($filters['scope'] ?? 'mine');
        $status = (string) ($filters['status'] ?? 'all');
        $priority = (string) ($filters['priority'] ?? 'all');
        $beforeId = (int) ($filters['before_id'] ?? 0);
        $limit = (int) ($filters['limit'] ?? 25);

        if (!in_array($scope, ['mine', 'unassigned', 'all'], true)
            || !in_array($status, ['all', 'open', 'in_progress', 'resolved'], true)
            || !in_array($priority, ['all', 'low', 'normal', 'high', 'critical'], true)
            || $beforeId < 0 || $limit < 1 || $limit > min(100, (int) config('crm67.max_page_size', 50))) {
            throw new DomainException('Invalid CRM inbox filters');
        }

        // Counters are a scoped operational projection, not a signed audit report.
        $counts = $this->scoped($actorId, $scope)
            ->selectRaw(<<<'SQL'
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE t.status = 'open') AS open,
                COUNT(*) FILTER (WHERE t.status = 'in_progress') AS in_progress,
                COUNT(*) FILTER (WHERE t.status = 'resolved') AS resolved,
                COUNT(*) FILTER (WHERE t.assigned_to IS NULL) AS unassigned,
                COUNT(*) FILTER (WHERE t.priority IN ('critical','high') AND t.status <> 'resolved') AS important_active
            SQL)
            ->first();

        $query = $this->scoped($actorId, $scope)
            ->join('orders as o', 'o.id', '=', 't.order_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 't.assigned_to')
            ->select(['t.*', 'o.order_key', 'assignee.name as assignee_name']);

        if ($status !== 'all') {
            $query->where('t.status', $status);
        }
        if ($priority !== 'all') {
            $query->where('t.priority', $priority);
        }
        if ($beforeId > 0) {
            $query->where('t.id', '<', $beforeId);
        }

        $rows = $query->orderByDesc('t.id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit)->values()->all();

        // Reuse CRM66 signature and projection checks for every task exposed.
        $verified = $this->tasks->verifiedBatch($visible);
        $now = CarbonImmutable::now('UTC');
        $items = [];

        foreach ($visible as $index => $row) {
            $created = CarbonImmutable::parse((string) $row->created_at);
            $items[] = [
                ...$verified[$index],
                'order_key' => (string) $row->order_key,
                'assignee_name' => $row->assignee_name === null ? null : (string) $row->assignee_name,
                'created_at' => (string) $row->created_at,
                // Age is informational, not an SLA deadline or delivery promise.
                'age_minutes' => max(0, (int) $created->diffInMinutes($now)),
            ];
        }

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? (int) $visible[count($visible) - 1]->id : null,
            'summary' => [
                'total' => (int) ($counts->total ?? 0),
                'open' => (int) ($counts->open ?? 0),
                'in_progress' => (int) ($counts->in_progress ?? 0),
                'resolved' => (int) ($counts->resolved ?? 0),
                'unassigned' => (int) ($counts->unassigned ?? 0),
                'important_active' => (int) ($counts->important_active ?? 0),
            ],
            'scope' => $scope,
            'read_only' => true,
            'external_requests_sent' => 0,
        ];
    }

    private function actor(): int
    {
        if (config('crm67.enabled') !== true
            || config('crm66.enabled') !== true
            || config('crm65.enabled') !== true) {
            abort(503, 'CRM inbox is disabled');
        }

        $user = Auth::user();
        if (!$user instanceof User) {
            abort(401);
        }
        Gate::forUser($user)->authorize('crm66.tasks.manage');
        return (int) $user->getKey();
    }

    private function scoped(int $actorId, string $scope): Builder
    {
        $query = DB::table('checkout_crm_tasks66 as t');

        return match ($scope) {
            'mine' => $query->where('t.assigned_to', $actorId),
            'unassigned' => $query->whereNull('t.assigned_to'),
            'all' => $query,
            default => throw new DomainException('Invalid CRM scope'),
        };
    }
}
