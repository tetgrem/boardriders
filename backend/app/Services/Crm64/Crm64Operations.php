<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use App\Services\Pricing\CheckoutCodTtnLifecycleService;
use App\Services\Pricing\CheckoutOrderReturnService;
use App\Services\Fiscal61\Fiscal61AuditService;
use App\Services\Fiscal62\Fiscal62Integrity;
use App\Services\Fiscal63\Fiscal63Integrity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reuses signed domain views; does not infer money, delivery or fiscal success. */
final class Crm64Operations
{
    public function __construct(
        private readonly Crm64Access $access,
        private readonly CheckoutOrderReturnService $returns,
        private readonly CheckoutCodTtnLifecycleService $delivery,
        private readonly Fiscal61AuditService $documents,
        private readonly Fiscal62Integrity $tickets,
        private readonly Fiscal63Integrity $incidents,
    ) {}

    public function returns(int $orderId): array
    {
        $count = (int) DB::table('checkout_order_return_cases')->where('order_id', $orderId)->count();
        if (!$this->access->has(CheckoutOrderReturnService::VIEW_ABILITY)) {
            return ['restricted' => true, 'case_count' => $count, 'items' => []];
        }
        $rows = DB::table('checkout_order_return_cases')->where('order_id', $orderId)
            ->orderBy('id')->get(['id','return_key']);
        $items = [];
        foreach ($rows as $row) {
            $view = $this->returns->show((string) $row->return_key);
            $items[] = [
                'case_id' => (int) $row->id,
                'return_key' => $view['return_key'],
                'reason_code' => $view['reason_code'],
                'requested_qty' => $view['requested_qty'],
                'workflow' => $view['workflow'],
                'decision' => $view['decision'],
                'money_refunded' => null, // RMA alone cannot establish refund execution.
                'rma_decision_is_not_refund_evidence' => true,
            ];
        }
        return ['restricted' => false, 'case_count' => $count, 'items' => $items];
    }

    public function shipping(int $orderId): array
    {
        $keys = DB::table('checkout_cod_ttn_tracking_cases as c')
            ->join('checkout_cod_ttn_submission_intents as i', 'i.id', '=', 'c.intent_id')
            ->where('i.order_id', $orderId)->orderBy('c.id')
            ->pluck('c.tracking_key')->all();
        if (!$this->access->has(CheckoutCodTtnLifecycleService::VIEW_ABILITY)) {
            return ['restricted' => true, 'tracking_count' => count($keys), 'items' => []];
        }
        $items = [];
        foreach ($keys as $key) {
            $state = $this->delivery->status((string) $key);
            $items[] = [
                'tracking_key' => (string) $key,
                'observed_state' => $state['observed_state'],
                'action_code' => $state['action_code'],
                'needs_review' => $state['needs_review'],
                'tracking_candidate_only' => true,
                'customer_delivery_confirmed' => false,
            ];
        }
        return ['restricted' => false, 'tracking_count' => count($keys), 'items' => $items];
    }

    public function fiscal(int $orderId): array
    {
        if (!$this->access->has(Crm64Access::FISCAL)) {
            return ['restricted' => true, 'documents' => []];
        }
        $rows = DB::table('checkout_fiscal_documents61')->where('order_id', $orderId)
            ->orderBy('id')->get(['id']);
        $documents = [];
        foreach ($rows as $row) {
            $draft = $this->documents->read((int) $row->id);
            $ticketRow = DB::table('checkout_fiscal_tickets62')->where('document_id', $row->id)->first(['id']);
            $ticket = null;
            $cases = [];
            if ($ticketRow !== null) {
                $verifiedTicket = $this->tickets->ticket((int) $ticketRow->id);
                $events = $this->tickets->events((int) $ticketRow->id);
                $done = $verifiedTicket['status'] === 'done'
                    && $verifiedTicket['fiscal_code'] !== null
                    && count(array_filter($events, static fn (array $ev): bool => $ev['kind'] === 'provider_done')) > 0;
                if ($verifiedTicket['status'] === 'done' && !$done) {
                    throw new RuntimeException('CRM fiscal DONE lacks signed provider completion');
                }
                $ticket = [
                    'ticket_id' => $verifiedTicket['ticket_id'],
                    'provider_receipt_id' => $verifiedTicket['provider_receipt_id'],
                    'status' => $verifiedTicket['status'],
                    'fiscal_code' => $done ? $verifiedTicket['fiscal_code'] : null,
                    'provider_done_evidence' => $done,
                ];
                $caseIds = DB::table('checkout_fiscal_cases63')
                    ->where('ticket_id', $ticketRow->id)->orderBy('id')->pluck('id');
                foreach ($caseIds as $caseId) {
                    $case = $this->incidents->read((int) $caseId);
                    $cases[] = [
                        'case_id' => $case['case_id'],
                        'reason_code' => $case['reason_code'],
                        'status' => $case['status'],
                    ];
                }
            }
            $documents[] = [
                'document_id' => $draft['document_id'],
                'kind' => $draft['kind'],
                'amount_minor' => $draft['amount_minor'],
                'currency_code' => $draft['currency_code'],
                'status' => $draft['status'],
                'ticket' => $ticket,
                'incidents' => $cases,
            ];
        }
        return ['restricted' => false, 'documents' => $documents];
    }
}
