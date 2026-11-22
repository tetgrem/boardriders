<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use DomainException;
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Indexed, cursor-paginated and privacy-minimal OMS read model. */
final class Crm64Orders
{
    public function __construct(private readonly Crm64Access $access) {}

    public function page(array $filters): array
    {
        $this->access->actor(Crm64Access::VIEW);
        $limit = (int) ($filters['limit'] ?? 20);
        $before = isset($filters['before_id']) ? (int) $filters['before_id'] : null;
        $status = $filters['payment_status'] ?? null;
        $key = $filters['order_key'] ?? null;
        $rma = $filters['has_rma'] ?? null;
        $issue = $filters['has_fiscal_issue'] ?? null;

        if ($limit < 1 || $limit > (int) config('crm64.max_page_size', 100)
            || ($before !== null && $before <= 0)
            || ($status !== null && (!is_string($status) || !preg_match('/^[a-z_]{2,32}$/D', $status)))
            || ($key !== null && (!is_string($key) || !preg_match('/^[0-9a-fA-F-]{1,36}$/D', $key)))
            || ($rma !== null && !is_bool($rma))
            || ($issue !== null && !is_bool($issue))) {
            throw new DomainException('Invalid CRM order filter');
        }

        if ($issue !== null && !$this->access->has(Crm64Access::FISCAL)) {
            throw new AuthorizationException('CRM fiscal filter access denied');
        }

        $q = DB::table('orders as o')
            ->leftJoin('order_financial_snapshots as s', 's.order_id', '=', 'o.id')
            ->select('o.id', 'o.order_key', 'o.payment_status', 's.goods_payable_minor', 's.currency_code', 's.snapshot_json', 's.snapshot_sha256')
            ->selectRaw('(SELECT COUNT(*) FROM checkout_order_return_cases c WHERE c.order_id = o.id) AS rma_count')
            ->selectRaw("(SELECT COUNT(*) FROM checkout_payment_capture_decisions d WHERE d.order_id = o.id AND d.decision = 'paid') AS paid_capture_count")
            ->selectRaw('(SELECT COUNT(*) FROM checkout_mono_refund_journal56 j WHERE j.order_id = o.id) AS journal_count')
            ->selectRaw("(SELECT COUNT(*) FROM checkout_fiscal_cases63 c JOIN checkout_fiscal_tickets62 t ON t.id = c.ticket_id JOIN checkout_fiscal_documents61 f ON f.id = t.document_id WHERE f.order_id = o.id AND NOT EXISTS (SELECT 1 FROM checkout_fiscal_case_actions63 a WHERE a.case_id = c.id AND a.action = 'close')) AS open_fiscal_case_count");

        if ($before !== null) $q->where('o.id', '<', $before);
        if ($status !== null) $q->where('o.payment_status', $status);
        if ($key !== null) $q->where('o.order_key', 'like', strtolower($key) . '%');
        if ($rma !== null) {
            $sub = fn ($sq) => $sq->selectRaw('1')->from('checkout_order_return_cases as r')->whereColumn('r.order_id', 'o.id');
            $rma ? $q->whereExists($sub) : $q->whereNotExists($sub);
        }
        if ($issue !== null) {
            $sub = fn ($sq) => $sq->selectRaw('1')
                ->from('checkout_fiscal_cases63 as c')
                ->join('checkout_fiscal_tickets62 as t', 't.id', '=', 'c.ticket_id')
                ->join('checkout_fiscal_documents61 as f', 'f.id', '=', 't.document_id')
                ->whereColumn('f.order_id', 'o.id')
                ->whereNotExists(fn ($inner) => $inner->selectRaw('1')
                    ->from('checkout_fiscal_case_actions63 as a')
                    ->whereColumn('a.case_id', 'c.id')->where('a.action', 'close'));
            $issue ? $q->whereExists($sub) : $q->whereNotExists($sub);
        }

        $rows = $q->orderByDesc('o.id')->limit($limit + 1)->get();
        $financial = $this->access->has(Crm64Access::FINANCE);
        $fiscal = $this->access->has(Crm64Access::FISCAL);
        $items = $rows->take($limit)->map(static function (object $r) use ($financial, $fiscal): array {
            if ($financial && $r->snapshot_sha256 !== null && !hash_equals(
                (string) $r->snapshot_sha256, hash('sha256', (string) $r->snapshot_json)
            )) {
                throw new RuntimeException('CRM list financial snapshot checksum mismatch');
            }
            return [
            'order_id' => (int) $r->id,
            'order_key' => (string) $r->order_key,
            'payment_status' => (string) $r->payment_status,
            'goods_payable_minor' => !$financial || $r->goods_payable_minor === null ? null : (int) $r->goods_payable_minor,
            'currency_code' => !$financial || $r->currency_code === null ? null : (string) $r->currency_code,
            'rma_count' => (int) $r->rma_count,
            'paid_capture_count' => $financial ? (int) $r->paid_capture_count : null,
            'journal_count' => $financial ? (int) $r->journal_count : null,
            'open_fiscal_case_count' => $fiscal ? (int) $r->open_fiscal_case_count : null,
            'card_settlement_confirmed' => false,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['order_id'] : null,
        ];
    }

    public function order(string $key): object
    {
        if (!Str::isUuid($key)) throw new DomainException('Invalid order UUID');
        $order = DB::table('orders')->where('order_key', strtolower($key))
            ->first(['id', 'order_key', 'payment_status']);
        if ($order === null) throw new DomainException('CRM order not found');
        return $order;
    }
}
