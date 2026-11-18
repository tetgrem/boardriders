<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutOrderReturnService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutOrderReturnController extends Controller
{
    public function cancel(Request $request, CheckoutOrderReturnService $service): JsonResponse
    {
        $v = $request->validate([
            'order_key' => ['required','uuid'],
            'operation_key' => ['required','uuid'],
            'reason' => ['sometimes','in:customer_cancel,merchant_cancel,reservation_timeout'],
        ]);
        return $this->respond(fn (): array => $service->cancelBeforeDispatch(
            $v['order_key'], $v['operation_key'], $v['reason'] ?? 'customer_cancel'
        ));
    }

    public function open(Request $request, CheckoutOrderReturnService $service): JsonResponse
    {
        $v = $request->validate([
            'order_key' => ['required','uuid'],
            'operation_key' => ['required','uuid'],
            'reason_code' => ['required','in:customer_return,merchant_return,damaged,wrong_item,carrier_refusal,carrier_unclaimed,carrier_return'],
            'items' => ['required','array','min:1','max:200'],
            'items.*.variant_id' => ['required','integer','min:1'],
            'items.*.quantity' => ['required','integer','between:1,99'],
            'source_lifecycle_event_id' => ['nullable','integer','min:1'],
        ]);
        return $this->respond(fn (): array => $service->open(
            $v['order_key'], $v['operation_key'], $v['reason_code'], $v['items'],
            $v['source_lifecycle_event_id'] ?? null
        ), 201);
    }

    public function inspect(Request $request, string $returnKey, CheckoutOrderReturnService $service): JsonResponse
    {
        $v = $request->validate([
            'operation_key' => ['required','uuid'],
            'warehouse_id' => ['required','integer','min:1'],
            'receipt_reference' => ['required','string','min:4','max:120'],
            'conditions' => ['required','array','min:1','max:20000'],
            'conditions.*.variant_id' => ['required','integer','min:1'],
            'conditions.*.unit_index' => ['required','integer','between:1,99'],
            'conditions.*.condition' => ['required','in:resellable,damaged,not_received'],
        ]);
        return $this->respond(fn (): array => $service->inspect(
            $returnKey, $v['operation_key'], (int) $v['warehouse_id'],
            $v['receipt_reference'], $v['conditions']
        ), 201);
    }

    public function decide(Request $request, string $returnKey, CheckoutOrderReturnService $service): JsonResponse
    {
        $v = $request->validate([
            'operation_key' => ['required','uuid'],
            'decision' => ['required','in:approved,rejected'],
        ]);
        return $this->respond(fn (): array => $service->decide(
            $returnKey, $v['operation_key'], $v['decision']
        ), 201);
    }

    public function show(string $returnKey, CheckoutOrderReturnService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->show($returnKey));
    }

    public function queue(Request $request, CheckoutOrderReturnService $service): JsonResponse
    {
        $v = $request->validate([
            'filter' => ['sometimes','in:all,inspection,review,execution,closed'],
            'limit' => ['sometimes','integer','between:1,100'],
            'before_id' => ['sometimes','integer','min:1'],
        ]);
        return $this->respond(fn (): array => $service->queue(
            $v['filter'] ?? 'all', (int) ($v['limit'] ?? 20),
            isset($v['before_id']) ? (int) $v['before_id'] : null
        ));
    }

    private function respond(callable $callback, int $successStatus = 200): JsonResponse
    {
        try {
            $data = $callback();
            return response()->json(
                $data,
                ($data['idempotent_replay'] ?? false) ? 200 : $successStatus
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
