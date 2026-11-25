<?php

declare(strict_types=1);

namespace App\Services\Crm70;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class Crm70Notifications
{
    public function __construct(
        private readonly Crm70Sources $sources
    ) {}

    public function page(array $filters): array
    {
        $user = $this->actor();

        $preferences = $this->preferencesFor($user);

        $categories = $this->enabledCategories($preferences);

        $category = (string) ($filters['category'] ?? 'all');
        $unreadOnly = (bool) ($filters['unread'] ?? false);

        $limit = (int) (
            $filters['limit']
            ?? config('crm70.default_page_size', 25)
        );

        $page = (int) ($filters['page'] ?? 1);

        $max = max(
            1,
            min(50, (int) config('crm70.max_page_size', 50))
        );

        if ($limit < 1 || $limit > $max
            || $page < 1 || $page > 1000) {
            abort(422, 'Invalid notification pagination');
        }

        if ($category !== 'all'
            && !array_key_exists($category, $preferences)) {
            abort(403, 'Notification category is not available');
        }

        $base = $this->feed($user, $categories);

        $summary = $this->summary($base);

        $query = clone $base;

        if ($category !== 'all') {
            $query->where('n.category', $category);
        }

        if ($unreadOnly) {
            $query->whereNull('r.read_at');
        }

        $rows = $query
            ->select([
                'n.notification_key',
                'n.category',
                'n.severity',
                'n.order_key',
                'n.source_id',
                'n.occurred_at',
                'r.read_at',
            ])
            ->orderByRaw("
                CASE n.severity
                    WHEN 'critical' THEN 0
                    WHEN 'warning' THEN 1
                    ELSE 2
                END
            ")
            ->orderByDesc('n.occurred_at')
            ->orderBy('n.notification_key')
            ->offset(($page - 1) * $limit)
            ->limit($limit + 1)
            ->get();

        $items = $rows->take($limit)
            ->map(fn (object $row): array => [
                'notification_key' => (string) $row->notification_key,
                'category' => (string) $row->category,
                'severity' => (string) $row->severity,
                'order_key' => (string) $row->order_key,
                'source_id' => (int) $row->source_id,
                'occurred_at' => $this->iso($row->occurred_at),
                'read_at' => $this->iso($row->read_at),
                'is_read' => $row->read_at !== null,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'summary' => $summary,
            'preferences' => $preferences,
            'page' => $page,
            'has_more' => $page < 1000 && $rows->count() > $limit,
        ];
    }

    public function unreadCount(): array
    {
        $user = $this->actor();

        $categories = $this->enabledCategories(
            $this->preferencesFor($user)
        );

        $count = $this->feed($user, $categories)
            ->whereNull('r.read_at')
            ->count();

        return [
            'unread' => (int) $count,
        ];
    }

    public function markRead(string $key): array
    {
        $user = $this->actor();

        if (!preg_match(
                '/^[a-z_]+:[0-9]+(?::[0-9]+)?$/D',
                $key
            ) || strlen($key) > 64) {
            abort(422, 'Invalid notification key');
        }

        $categories = $this->enabledCategories(
            $this->preferencesFor($user)
        );

        $exists = $this->feed($user, $categories)
            ->where('n.notification_key', $key)
            ->exists();

        if (!$exists) {
            abort(404, 'Notification is not available');
        }

        DB::table('checkout_crm_notification_reads70')
            ->insertOrIgnore([
                'user_id' => (int) $user->id,
                'notification_key' => $key,
                'read_at' => now(),
            ]);

        $readAt = DB::table('checkout_crm_notification_reads70')
            ->where('user_id', $user->id)
            ->where('notification_key', $key)
            ->value('read_at');

        return [
            'notification_key' => $key,
            'is_read' => true,
            'read_at' => $this->iso($readAt),
        ];
    }

    public function markUnread(string $key): array
    {
        $user = $this->actor();

        $categories = $this->enabledCategories(
            $this->preferencesFor($user)
        );

        if (!$this->feed($user, $categories)
            ->where('n.notification_key', $key)
            ->exists()) {
            abort(404, 'Notification is not available');
        }

        DB::table('checkout_crm_notification_reads70')
            ->where('user_id', $user->id)
            ->where('notification_key', $key)
            ->delete();

        return [
            'notification_key' => $key,
            'is_read' => false,
        ];
    }

    public function setPreference(
        string $category,
        bool $enabled
    ): array {
        $user = $this->actor();

        if (!in_array(
            $category,
            $this->sources->allowed($user),
            true
        )) {
            abort(403, 'Notification category is not available');
        }

        DB::table('checkout_crm_notification_preferences70')
            ->upsert(
                [[
                    'user_id' => (int) $user->id,
                    'category' => $category,
                    'enabled' => $enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'category'],
                ['enabled', 'updated_at']
            );

        return [
            'preferences' => $this->preferencesFor($user),
        ];
    }

    private function preferencesFor(User $user): array
    {
        $allowed = $this->sources->allowed($user);

        $stored = DB::table(
            'checkout_crm_notification_preferences70'
        )
            ->where('user_id', $user->id)
            ->pluck('enabled', 'category');

        $result = [];

        foreach ($allowed as $category) {
            $result[$category] = $stored->has($category)
                ? (bool) $stored->get($category)
                : true;
        }

        return $result;
    }

    private function enabledCategories(
        array $preferences
    ): array {
        return array_keys(
            array_filter(
                $preferences,
                static fn (bool $enabled): bool => $enabled
            )
        );
    }

    private function feed(
        User $user,
        array $categories
    ): Builder {
        return DB::query()
            ->fromSub(
                $this->sources->query($categories),
                'n'
            )
            ->leftJoin(
                'checkout_crm_notification_reads70 as r',
                function (JoinClause $join) use ($user): void {
                    $join->on(
                        'r.notification_key',
                        '=',
                        'n.notification_key'
                    )->where(
                        'r.user_id',
                        '=',
                        (int) $user->id
                    );
                }
            );
    }

    private function summary(Builder $base): array
    {
        $row = (clone $base)
            ->selectRaw("
                COUNT(*) AS total,

                COUNT(*) FILTER (
                    WHERE r.read_at IS NULL
                ) AS unread,

                COUNT(*) FILTER (
                    WHERE r.read_at IS NULL
                    AND n.severity = 'critical'
                ) AS critical,

                COUNT(*) FILTER (
                    WHERE r.read_at IS NULL
                    AND n.severity = 'warning'
                ) AS warning
            ")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'unread' => (int) ($row->unread ?? 0),
            'critical' => (int) ($row->critical ?? 0),
            'warning' => (int) ($row->warning ?? 0),
        ];
    }

    private function actor(): User
    {
        if (config('crm70.enabled') !== true
            || config('crm65.enabled') !== true
            || config('crm64.enabled') !== true) {
            abort(503, 'CRM notifications disabled');
        }

        $user = Auth::user();

        if (!$user instanceof User) {
            abort(401);
        }

        Gate::forUser($user)->authorize(
            'crm64.orders.view'
        );

        return $user;
    }

    private function iso(mixed $date): ?string
    {
        return $date === null
            ? null
            : CarbonImmutable::parse((string) $date)
                ->utc()
                ->toIso8601String();
    }
}
