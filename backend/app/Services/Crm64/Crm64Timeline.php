<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use App\Services\Pricing\CheckoutCodTtnLifecycleService;
use App\Services\Pricing\CheckoutOrderReturnService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** A bounded, privacy-scoped operational projection; no side effects or provider API calls. */
final class Crm64Timeline
{
    public function __construct(private readonly Crm64Access $access, private readonly Crm64Notes $notes) {}

    public function collect(int $orderId, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) throw new DomainException('Invalid CRM timeline size');
        $perSource = (int) config('crm64.timeline_per_source', 100);
        $perSource = max(1, min(100, $perSource));
        $events = [];
        $truncated = false;
        $push = function (string $source, iterable $rows, callable $map) use (&$events, &$truncated, $perSource): void {
            $count = 0;
            foreach ($rows as $r) {
                $count++;
                if ($count > $perSource) {
                    $truncated = true;
                    break;
                }
                $event = $map($r);
                $events[] = [
                    'source' => $source,
                    'source_id' => (int) $r->id,
                    'created_at' => CarbonImmutable::parse($event['date'])->utc()->format('Y-m-d H:i:s.u'),
                    'event' => $event['event'],
                    'detail' => $event['detail'] ?? null,
                ];
            }
        };
        $baseLimit = $perSource + 1;
        if ($this->access->has(Crm64Access::FINANCE)) {
            $push('capture', DB::table('checkout_payment_capture_receipts')->where('order_id', $orderId)
                ->orderByDesc('id')->limit($baseLimit)->get(['id','captured_at','provider_code']),
                static fn (object $r): array => ['date' => $r->captured_at, 'event' => 'capture_recorded', 'detail' => $r->provider_code]);
            $push('refund_journal', DB::table('checkout_mono_refund_journal56')->where('order_id', $orderId)
                ->orderByDesc('id')->limit($baseLimit)->get(['id','created_at']),
                static fn (object $r): array => ['date' => $r->created_at, 'event' => 'refund_provider_confirmed']);
        }
        if ($this->access->has(CheckoutOrderReturnService::VIEW_ABILITY)) {
            $push('rma', DB::table('checkout_order_return_cases')->where('order_id', $orderId)
                ->orderByDesc('id')->limit($baseLimit)->get(['id','created_at','reason_code']),
                static fn (object $r): array => ['date' => $r->created_at, 'event' => 'rma_opened', 'detail' => $r->reason_code]);
        }
        if ($this->access->has(CheckoutCodTtnLifecycleService::VIEW_ABILITY)) {
            $push('carrier', DB::table('checkout_cod_ttn_lifecycle_events as e')
                ->join('checkout_cod_ttn_tracking_cases as c', 'c.id', '=', 'e.tracking_case_id')
                ->join('checkout_cod_ttn_submission_intents as i', 'i.id', '=', 'c.intent_id')
                ->where('i.order_id', $orderId)->orderByDesc('e.id')->limit($baseLimit)
                ->get(['e.id','e.created_at','e.action_code']),
                static fn (object $r): array => ['date' => $r->created_at, 'event' => 'carrier_evidence', 'detail' => $r->action_code]);
        }
        if ($this->access->has(Crm64Access::FISCAL)) {
            $push('fiscal_draft', DB::table('checkout_fiscal_documents61')->where('order_id', $orderId)
                ->orderByDesc('id')->limit($baseLimit)->get(['id','created_at','kind']),
                static fn (object $r): array => ['date' => $r->created_at, 'event' => 'fiscal_draft_prepared', 'detail' => $r->kind]);
            $push('fiscal_case', DB::table('checkout_fiscal_cases63 as c')
                ->join('checkout_fiscal_tickets62 as t', 't.id', '=', 'c.ticket_id')
                ->join('checkout_fiscal_documents61 as d', 'd.id', '=', 't.document_id')
                ->where('d.order_id', $orderId)->orderByDesc('c.id')->limit($baseLimit)
                ->get(['c.id','c.created_at','c.reason_code']),
                static fn (object $r): array => ['date' => $r->created_at, 'event' => 'fiscal_incident_opened', 'detail' => $r->reason_code]);
        }
        $noteRows = $this->notes->history($orderId, $perSource);
        foreach ($noteRows as $idx => $n) {
            if ($idx >= $perSource) { $truncated = true; break; }
            $events[] = [
                'source' => 'crm_note', 'source_id' => $n['note_id'],
                'created_at' => CarbonImmutable::parse($n['created_at'])->utc()->format('Y-m-d H:i:s.u'),
                'event' => 'note_written', 'detail' => $n['category'],
            ];
        }
        usort($events, static function (array $a, array $b): int {
            $compare = strcmp($b['created_at'], $a['created_at']);
            return $compare !== 0 ? $compare : (strcmp($b['source'], $a['source']) ?: ($b['source_id'] <=> $a['source_id']));
        });
        $hasMore = count($events) > $limit;
        return [
            'items' => array_slice($events, 0, $limit),
            'truncated' => $truncated || $hasMore,
            'is_operational_evidence_not_settlement' => true,
            'external_requests_sent' => 0,
        ];
    }
}
