<?php

declare(strict_types=1);

namespace App\Services\Crm70;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class Crm70Sources
{
    public const ABILITIES = [
        'orders' => 'crm64.orders.view',
        'payments' => 'crm64.finance.view',
        'returns' => 'orders.returns.view',
        'delivery' => 'cod.operations.ttn_lifecycle.view',
        'fiscal' => 'crm64.fiscal.view',
        'sla' => 'crm66.tasks.manage',
    ];

    public function allowed(User $user): array
    {
        $categories = [];

        foreach (self::ABILITIES as $category => $ability) {
            if ($category === 'sla' && (
                    config('crm69.enabled') !== true
                    || config('crm66.enabled') !== true
                )) {
                continue;
            }

            if (Gate::forUser($user)->allows($ability)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    public function query(array $categories): Builder
    {
        $queries = [];

        foreach ($categories as $category) {
            $queries[] = match ($category) {
                'orders' => $this->orders(),
                'payments' => $this->payments(),
                'returns' => $this->returns(),
                'delivery' => $this->delivery(),
                'fiscal' => $this->fiscal(),
                'sla' => $this->sla(),
                default => throw new \DomainException(
                    'Unknown notification category'
                ),
            };
        }

        if ($queries === []) {
            return DB::query()
                ->selectRaw("
                    NULL::text AS notification_key,
                    NULL::text AS category,
                    NULL::text AS severity,
                    NULL::uuid AS order_key,
                    NULL::bigint AS source_id,
                    NULL::timestamptz AS occurred_at
                ")
                ->whereRaw('1 = 0');
        }

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return $union;
    }

    private function orders(): Builder
    {
        $days = max(
            1,
            min(30, (int) config('crm70.new_order_days', 7))
        );

        return DB::table('orders as o')
            ->where('o.created_at', '>=', now()->subDays($days))
            ->selectRaw("
                'orders:' || o.id::text AS notification_key,
                'orders'::text AS category,
                'info'::text AS severity,
                o.order_key,
                o.id AS source_id,
                o.created_at AS occurred_at
            ");
    }

    private function payments(): Builder
    {
        return DB::table('orders as o')
            ->where('o.payment_status', 'review')
            ->selectRaw("
                'payments:' || o.id::text AS notification_key,
                'payments'::text AS category,
                'critical'::text AS severity,
                o.order_key,
                o.id AS source_id,
                COALESCE(o.updated_at, o.created_at) AS occurred_at
            ");
    }

    private function returns(): Builder
    {
        return DB::table('checkout_order_return_cases as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('checkout_order_return_decisions as d')
                    ->whereColumn('d.case_id', 'r.id');
            })
            ->selectRaw("
                'returns:' || r.id::text AS notification_key,
                'returns'::text AS category,
                'warning'::text AS severity,
                o.order_key,
                r.id AS source_id,
                r.created_at AS occurred_at
            ");
    }

    private function delivery(): Builder
    {
        return DB::table('checkout_cod_ttn_lifecycle_events as e')
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->where('e.needs_review', true)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('checkout_cod_ttn_lifecycle_events as newer')
                    ->whereColumn(
                        'newer.tracking_case_id',
                        'e.tracking_case_id'
                    )
                    ->whereColumn('newer.id', '>', 'e.id');
            })
            ->selectRaw("
                'delivery:' || e.id::text AS notification_key,
                'delivery'::text AS category,
                'warning'::text AS severity,
                o.order_key,
                e.id AS source_id,
                e.created_at AS occurred_at
            ");
    }

    private function fiscal(): Builder
    {
        return DB::table('checkout_fiscal_cases63 as c')
            ->join(
                'checkout_fiscal_tickets62 as t',
                't.id',
                '=',
                'c.ticket_id'
            )
            ->join(
                'checkout_fiscal_documents61 as f',
                'f.id',
                '=',
                't.document_id'
            )
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('checkout_fiscal_case_actions63 as a')
                    ->whereColumn('a.case_id', 'c.id')
                    ->where('a.action', 'close');
            })
            ->selectRaw("
                'fiscal:' || c.id::text AS notification_key,
                'fiscal'::text AS category,
                'critical'::text AS severity,
                o.order_key,
                c.id AS source_id,
                c.created_at AS occurred_at
            ");
    }

    private function sla(): Builder
    {
        return DB::table('checkout_crm_task_sla69 as s')
            ->join(
                'checkout_crm_tasks66 as t',
                't.id',
                '=',
                's.task_id'
            )
            ->join('orders as o', 'o.id', '=', 't.order_id')
            ->where('t.status', '<>', 'resolved')
            ->whereNotNull('s.due_at')
            ->where('s.due_at', '<', now())
            ->selectRaw("
                'sla:' || t.id::text || ':' || s.revision::text
                    AS notification_key,
                'sla'::text AS category,
                'warning'::text AS severity,
                o.order_key,
                t.id AS source_id,
                s.due_at AS occurred_at
            ");
    }
}
