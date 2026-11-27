<?php

declare(strict_types=1);

namespace App\Services\Crm74;

use App\Services\Loyalty\LoyaltyWalletPreviewService;
use App\Services\Pricing\OrderFinancialSnapshotService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class Crm74Customers
{
    public function __construct(
        private readonly Crm74Audit $audit,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly LoyaltyWalletPreviewService $walletPreview
    ) {}

    private function customerOrders(int $customerId): Builder
    {
        return DB::table('checkout_order_preparations as prep')
            ->join('orders as o', 'o.id', '=', 'prep.order_id')
            ->where('prep.user_id', $customerId);
    }

    private function directoryQuery(): Builder
    {
        $counts = DB::table('checkout_order_preparations as prep')
            ->join('orders as o', 'o.id', '=', 'prep.order_id')
            ->whereNotNull('prep.user_id')
            ->groupBy('prep.user_id')
            ->select('prep.user_id')
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw(<<<'SQL'
                COUNT(*) FILTER (
                    WHERE o.payment_status = 'paid'
                ) AS paid_count
            SQL)
            ->selectRaw(<<<'SQL'
                MAX(o.created_at) FILTER (
                    WHERE o.payment_status = 'paid'
                ) AS last_paid_order_at
            SQL);

        $days = max(1, min(
            3650,
            (int) config('crm74.inactive_days', 90)
        ));

        $vip = max(
            2,
            (int) config('crm74.vip_paid_orders', 5)
        );

        return $this->audit->customers()
            ->leftJoinSub($counts, 'stats', function ($join): void {
                $join->on('stats.user_id', '=', 'u.id');
            })
            ->select([
                'u.id',
                'u.name',
                'u.email',
                'u.email_verified_at',
                'u.created_at',
                'stats.last_paid_order_at',
            ])
            ->selectRaw(
                'COALESCE(stats.orders_count, 0) AS orders_count'
            )
            ->selectRaw(
                'COALESCE(stats.paid_count, 0) AS paid_count'
            )
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN COALESCE(stats.paid_count, 0) = 0
                        THEN 'prospect'
                    WHEN stats.last_paid_order_at <
                        CURRENT_TIMESTAMP - (? * INTERVAL '1 day')
                        THEN 'inactive'
                    WHEN stats.paid_count >= ?
                        THEN 'vip'
                    WHEN stats.paid_count >= 2
                        THEN 'regular'
                    ELSE 'new'
                END AS segment
            SQL, [$days, $vip]);
    }

    private function formatCustomer(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'email' => (string) $row->email,

            'email_verified' =>
                $row->email_verified_at !== null,

            'created_at' => $row->created_at,

            'orders_count' => (int) $row->orders_count,
            'paid_count' => (int) $row->paid_count,

            'last_paid_order_at' => $row->last_paid_order_at,

            'segment' => (string) $row->segment,
        ];
    }

    public function directory(array $filters): array
    {
        $this->audit->actor();

        $query = DB::query()->fromSub(
            $this->directoryQuery(),
            'customers'
        );

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $pattern = '%' . str_replace(
                    ['\\', '%', '_'],
                    ['\\\\', '\\%', '\\_'],
                    $search
                ) . '%';

            $query->where(function (Builder $sub) use (
                $search,
                $pattern
            ): void {
                $sub->where('name', 'ilike', $pattern)
                    ->orWhere('email', 'ilike', $pattern);

                if (
                    ctype_digit($search)
                    && strlen($search) <= 16
                ) {
                    $sub->orWhere('id', (int) $search);
                }
            });
        }

        $segment = $filters['segment'] ?? 'all';

        if ($segment !== 'all') {
            $query->where('segment', $segment);
        }

        $page = $this->page($query, 'id', $filters);

        $page['items'] = array_map(
            fn (object $row): array =>
            $this->formatCustomer($row),
            $page['items']
        );

        return $page;
    }

    public function overview(): array
    {
        $this->audit->actor();

        $counts = DB::query()
            ->fromSub($this->directoryQuery(), 'customers')
            ->groupBy('segment')
            ->select('segment')
            ->selectRaw('COUNT(*) AS total')
            ->pluck('total', 'segment');

        $segments = [];

        foreach ([
                     'prospect',
                     'new',
                     'regular',
                     'vip',
                     'inactive',
                 ] as $segment) {
            $segments[$segment] = (int) (
                $counts[$segment] ?? 0
            );
        }

        return [
            'customers_total' => array_sum($segments),
            'segments' => $segments,

            'guest_orders_count' => (int) DB::table(
                'checkout_order_preparations'
            )->whereNull('user_id')->count(),

            'vip_paid_orders' => (int) config(
                'crm74.vip_paid_orders', 5
            ),

            'inactive_days' => (int) config(
                'crm74.inactive_days', 90
            ),
        ];
    }

    public function profile(int $customerId): array
    {
        $actor = $this->audit->actor();

        $this->audit->customer($customerId);

        $row = $this->directoryQuery()
            ->where('u.id', $customerId)
            ->first();

        if ($row === null) {
            abort(404);
        }

        $contact = $this->audit->latestContact($customerId);

        $contactData = $contact['payload'] ?? [];

        $financial = $this->audit->has(
            Crm74Audit::FINANCE
        );

        $result = [
            'customer' => $this->formatCustomer($row),

            'contact' => [
                'contact_id' => $contact['id'] ?? null,

                'phone' => $contactData['phone'] ?? null,
                'city' => $contactData['city'] ?? null,
                'address' => $contactData['address'] ?? null,

                'preferred_channel' =>
                    $contactData['preferred_channel'] ?? 'none',

                'updated_at' => $contact['created_at'] ?? null,

                'source' => 'crm_staff',
                'customer_verified' => false,
            ],

            'statistics' => [
                'financial_restricted' => !$financial,

                'currencies' => $financial
                    ? $this->financialSummary($customerId)
                    : [],
            ],

            'can_manage' => $this->audit->has(
                Crm74Audit::MANAGE
            ),

            'can_view_returns' => $this->audit->has(
                'orders.returns.view'
            ),

            'can_view_access' => $this->audit->has(
                'crm64.reporting.view'
            ),
        ];

        $this->audit->viewed(
            $customerId,
            $actor,
            'profile'
        );

        return $result;
    }

    private function financialSummary(int $customerId): array
    {
        // Gross value of goods in orders marked paid.
        // This is not settled cash or net revenue.
        $rows = $this->customerOrders($customerId)
            ->join(
                'order_financial_snapshots as fs',
                function ($join): void {
                    $join->on('fs.id', '=', 'prep.financial_snapshot_id')
                        ->on('fs.order_id', '=', 'o.id');
                }
            )
            ->join('currencies as cu', 'cu.code', '=', 'fs.currency_code')
            ->where('o.payment_status', 'paid')
            ->groupBy('fs.currency_code', 'cu.minor_units')
            ->orderBy('fs.currency_code')
            ->select([
                'fs.currency_code',
                'cu.minor_units',
            ])
            ->selectRaw('COUNT(*) AS paid_orders')
            ->selectRaw(
                'SUM(fs.goods_payable_minor)::text AS total_minor'
            )
            ->selectRaw(
                'ROUND(AVG(fs.goods_payable_minor))::text AS average_minor'
            )
            ->get();

        return $rows->map(static fn (object $row): array => [
            'currency_code' => (string) $row->currency_code,
            'minor_units' => (int) $row->minor_units,
            'paid_orders' => (int) $row->paid_orders,

            'goods_total_minor' => (string) $row->total_minor,
            'average_goods_minor' => (string) $row->average_minor,

            'net_revenue' => false,
        ])->all();
    }

    public function orders(
        int $customerId,
        array $filters
    ): array {
        $actor = $this->audit->actor();

        $this->audit->customer($customerId);

        $query = $this->customerOrders($customerId)
            ->select([
                'o.id',
                'o.order_key',
                'o.payment_status',
                'o.created_at',
                'prep.market_id',
            ]);

        if (isset($filters['payment_status'])) {
            $query->where(
                'o.payment_status',
                $filters['payment_status']
            );
        }

        $page = $this->page(
            $query,
            'o.id',
            $filters
        );

        $finance = $this->audit->has(
            Crm74Audit::FINANCE
        );

        $page['items'] = array_map(
            function (object $row) use ($finance): array {
                $snapshot = $finance
                    ? $this->snapshots->findForOrder((int) $row->id)
                    : null;

                return [
                    'id' => (int) $row->id,
                    'order_key' => (string) $row->order_key,
                    'payment_status' => $row->payment_status,
                    'created_at' => $row->created_at,

                    'goods_payable_minor' => $snapshot === null
                        ? null
                        : (string) $snapshot['goods_payable_minor'],

                    'currency_code' =>
                        $snapshot['currency_code'] ?? null,

                    'minor_units' => $snapshot === null
                        ? null
                        : (int) DB::table('currencies')
                            ->where('code', $snapshot['currency_code'])
                            ->value('minor_units'),
                ];
            },
            $page['items']
        );

        $this->audit->viewed(
            $customerId,
            $actor,
            'orders'
        );

        return $page;
    }

    public function returns(
        int $customerId,
        array $filters
    ): array {
        $actor = $this->audit->actor();

        Gate::authorize('orders.returns.view');

        $this->audit->customer($customerId);

        $query = $this->customerOrders($customerId)
            ->join(
                'checkout_order_return_cases as r',
                'r.order_id',
                '=',
                'o.id'
            )
            ->select([
                'r.id',
                'r.return_key',
                'r.reason_code',
                'r.created_at',
                'o.order_key',
            ]);

        $page = $this->page(
            $query,
            'r.id',
            $filters
        );

        $this->audit->viewed(
            $customerId,
            $actor,
            'returns'
        );

        return $page;
    }

    private function customerWallets(int $customerId): Builder
    {
        return DB::table('loyalty_wallets as w')
            ->join(
                'loyalty_accounts as a',
                'a.id',
                '=',
                'w.loyalty_account_id'
            )
            ->join('markets as m', 'm.id', '=', 'w.market_id')
            ->where('a.user_id', $customerId)
            ->where('a.identity_state', 'verified');
    }

    public function wallets(
        int $customerId,
        array $filters
    ): array {
        $actor = $this->audit->actor();

        $this->audit->customer($customerId);

        $query = $this->customerWallets($customerId)
            ->select([
                'w.id',
                'w.status',
                'm.code as market_code',
                'm.name as market_name',
                'm.currency_code',
                'm.is_active as market_active',
            ]);

        $page = $this->page(
            $query,
            'w.id',
            $filters
        );

        $page['items'] = array_map(
            function (object $row) use ($customerId): array {
                $preview = (bool) $row->market_active
                    ? $this->walletPreview->forVerifiedUser(
                        $customerId,
                        (string) $row->market_code
                    )
                    : null;

                return [
                    'id' => (int) $row->id,
                    'market_code' => $row->market_code,
                    'market_name' => $row->market_name,
                    'currency_code' => $row->currency_code,
                    'status' => $row->status,
                    'market_active' => (bool) $row->market_active,
                    'balance' => $preview,
                ];
            },
            $page['items']
        );

        $this->audit->viewed(
            $customerId,
            $actor,
            'wallets'
        );

        return $page;
    }

    public function ledger(
        int $customerId,
        int $walletId,
        array $filters
    ): array {
        $actor = $this->audit->actor();

        $this->audit->customer($customerId);

        if (!$this->customerWallets($customerId)
            ->where('w.id', $walletId)
            ->exists()) {
            abort(404, 'Wallet not found');
        }

        $query = DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $walletId)
            ->select([
                'id',
                'entry_type',
                'origin',
                'pending_delta',
                'available_delta',
                'reserved_delta',
                'debt_delta',
                'occurred_at',
            ]);

        $page = $this->page(
            $query,
            'id',
            $filters
        );

        $this->audit->viewed(
            $customerId,
            $actor,
            'ledger'
        );

        return $page;
    }

    public function history(
        int $customerId,
        array $filters,
        bool $access = false
    ): array {
        $actor = $this->audit->actor();

        if ($access) {
            Gate::authorize('crm64.reporting.view');
        }

        $this->audit->customer($customerId);

        $query = DB::table(
            'checkout_crm_customer_events74 as e'
        )->leftJoin(
            'users as u',
            'u.id',
            '=',
            'e.actor_id'
        )->where('e.customer_id', $customerId)
            ->select([
                'e.*',
                'u.name as actor_name',
            ]);

        if ($access) {
            $query->whereIn('e.kind', [
                'view',
                'contact',
            ]);
        } else {
            $query->whereNotIn('e.kind', [
                'view',
                'contact',
            ]);
        }

        $page = $this->page(
            $query,
            'e.id',
            $filters
        );

        $page['items'] = array_map(
            function (object $row) use ($access): array {
                $event = $this->audit->verify($row);

                return [
                    'id' => $event['id'],
                    'kind' => $event['kind'],
                    'actor_id' => $event['actor_id'],
                    'actor_name' => $row->actor_name,
                    'created_at' => $event['created_at'],

                    'payload' => $access
                        ? [
                            'section' =>
                                $event['payload']['section'] ?? 'contact',
                        ]
                        : $event['payload'],
                ];
            },
            $page['items']
        );

        $this->audit->viewed(
            $customerId,
            $actor,
            $access ? 'access' : 'history'
        );

        return $page;
    }

    private function page(
        Builder $query,
        string $column,
        array $filters
    ): array {
        $limit = max(1, min(
            (int) config('crm74.max_page_size', 50),
            (int) ($filters['limit']
                ?? config('crm74.page_size', 25))
        ));

        if (isset($filters['before_id'])) {
            $query->where(
                $column,
                '<',
                (int) $filters['before_id']
            );
        }

        $rows = $query->orderByDesc($column)
            ->limit($limit + 1)
            ->get();

        $items = $rows->take($limit)
            ->values()
            ->all();

        return [
            'items' => $items,

            'next_before_id' =>
                $rows->count() > $limit && $items !== []
                    ? (int) $items[count($items) - 1]->id
                    : null,
        ];
    }
}
