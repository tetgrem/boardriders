<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use Illuminate\Support\Facades\DB;

final class Crm64Dashboard
{
    public function __construct(private readonly Crm64Access $access) {}

    public function summary(): array
    {
        $this->access->actor(Crm64Access::REPORT);
        $statusCounts = DB::table('orders')
            ->select('payment_status', DB::raw('COUNT(*) AS n'))
            ->groupBy('payment_status')->get()->mapWithKeys(
                static fn (object $r): array => [(string) $r->payment_status => (int) $r->n]
            )->all();
        $result = [
            'orders_total' => (int) DB::table('orders')->count(),
            'orders_by_payment_status' => $statusCounts,
            'rma_cases' => (int) DB::table('checkout_order_return_cases')->count(),
            'fiscal_issues' => $this->access->has(Crm64Access::FISCAL)
                ? (int) DB::table('checkout_fiscal_cases63 as c')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')
                        ->from('checkout_fiscal_case_actions63 as a')
                        ->whereColumn('a.case_id', 'c.id')->where('a.action', 'close'))->count()
                : null,
            'confirmed_refund_journal_count' => $this->access->has(Crm64Access::FINANCE)
                ? (int) DB::table('checkout_mono_refund_journal56')->count() : null,
            'finance_totals_included' => false,
            'read_only_projection' => true,
        ];
        return $result;
    }
}
