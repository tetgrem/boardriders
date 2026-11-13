<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodOperationsCaseService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodOperationsCaseController extends Controller
{
    public function index(
        Request $request,
        CheckoutCodOperationsCaseService $service
    ): JsonResponse {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'filter' => [
                'sometimes',
                'in:all,active,mine,overdue,stale',
            ],
        ]);

        return $this->respond(fn (): array => $service->queue(
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20,
            $v['filter'] ?? 'all'
        ));
    }

    public function open(
        Request $request,
        string $postingKey,
        CheckoutCodOperationsCaseService $service
    ): JsonResponse {
        $v = $request->validate([
            'operation_key' => ['required', 'uuid'],
        ]);

        return $this->respond(fn (): array => $service->open(
            $postingKey,
            $v['operation_key']
        ));
    }

    public function show(
        string $caseKey,
        CheckoutCodOperationsCaseService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array => $service->inspect($caseKey)
        );
    }

    public function history(
        Request $request,
        string $caseKey,
        CheckoutCodOperationsCaseService $service
    ): JsonResponse {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return $this->respond(fn (): array => $service->history(
            $caseKey,
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 30
        ));
    }

    public function action(
        Request $request,
        string $caseKey,
        CheckoutCodOperationsCaseService $service
    ): JsonResponse {
        $v = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'expected_snapshot_hmac' => [
                'required',
                'regex:/^[a-f0-9]{64}$/D',
            ],
            'action' => [
                'required',
                'in:claim,request_evidence,resume,release,close,refresh',
            ],
            'reason_code' => ['required', 'string', 'max:40'],
        ]);

        return $this->respond(fn (): array => $service->act(
            $caseKey,
            $v['operation_key'],
            (int) $v['expected_revision'],
            $v['expected_snapshot_hmac'],
            $v['action'],
            $v['reason_code']
        ));
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}
