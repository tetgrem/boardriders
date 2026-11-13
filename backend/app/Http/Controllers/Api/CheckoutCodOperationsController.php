<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodOperationsReviewService;
use App\Services\Pricing\CheckoutCodOperationsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodOperationsController extends Controller
{
    public function index(
        Request $request,
        CheckoutCodOperationsService $service
    ): JsonResponse {
        $data = $request->validate([
            'before_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'limit' => [
                'sometimes',
                'integer',
                'between:1,50',
            ],
            'filter' => [
                'sometimes',
                'in:all,attention,awaiting_register',
            ],
        ]);

        return $this->handle(fn (): array => $service->queue(
            isset($data['before_id'])
                ? (int) $data['before_id']
                : null,
            isset($data['limit'])
                ? (int) $data['limit']
                : 20,
            $data['filter'] ?? 'all'
        ));
    }

    public function show(
        string $postingKey,
        CheckoutCodOperationsService $service
    ): JsonResponse {
        return $this->handle(
            fn (): array => $service->inspect($postingKey)
        );
    }

    public function review(
        Request $request,
        string $postingKey,
        CheckoutCodOperationsReviewService $service
    ): JsonResponse {
        $data = $request->validate([
            'operation_key' => [
                'required',
                'uuid',
            ],
            'expected_snapshot_hmac' => [
                'required',
                'regex:/^[a-f0-9]{64}$/D',
            ],
            'action' => [
                'required',
                'in:request_evidence,escalate_investigation,acknowledge_comparison',
            ],
            'reason_code' => [
                'required',
                'string',
                'max:40',
            ],
        ]);

        return $this->handle(
            fn (): array => $service->record(
                $postingKey,
                $data['operation_key'],
                $data['expected_snapshot_hmac'],
                $data['action'],
                $data['reason_code']
            )
        );
    }

    private function handle(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (AuthorizationException $e) {
            // Laravel converts this exception to HTTP 403.
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
