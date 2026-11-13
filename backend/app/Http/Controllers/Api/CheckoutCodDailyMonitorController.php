<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodDailyMonitorService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodDailyMonitorController extends Controller
{
    public function index(Request $request, CheckoutCodDailyMonitorService $service): JsonResponse
    {
        $v = $request->validate([
            'scope' => ['sometimes', 'in:all,attention,unacknowledged'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return $this->respond(fn (): array => $service->listing(
            $v['scope'] ?? 'attention',
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function history(
        Request $request,
        string $reportKey,
        CheckoutCodDailyMonitorService $service
    ): JsonResponse {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return $this->respond(fn (): array => $service->history(
            $reportKey,
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function acknowledge(
        Request $request,
        int $signalId,
        CheckoutCodDailyMonitorService $service
    ): JsonResponse {
        $v = $request->validate(['operation_key' => ['required', 'uuid']]);

        return $this->respond(fn (): array => $service->acknowledge(
            $signalId, $v['operation_key']
        ));
    }

    private function respond(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
