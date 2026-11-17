<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodTtnLifecycleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodTtnLifecycleController extends Controller
{
    public function status(string $trackingKey, CheckoutCodTtnLifecycleService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->status($trackingKey));
    }

    public function sync(Request $request, string $trackingKey, CheckoutCodTtnLifecycleService $service): JsonResponse
    {
        $v = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,200']]);
        return $this->respond(fn (): array => $service->sync($trackingKey, (int) ($v['limit'] ?? 100)));
    }

    public function history(Request $request, string $trackingKey, CheckoutCodTtnLifecycleService $service): JsonResponse
    {
        $v = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->respond(fn (): array => $service->history(
            $trackingKey, (int) ($v['limit'] ?? 50), isset($v['before_id']) ? (int) $v['before_id'] : null
        ));
    }

    public function queue(Request $request, CheckoutCodTtnLifecycleService $service): JsonResponse
    {
        $v = $request->validate([
            'filter' => ['sometimes', 'in:all,return,pickup,risk,cod'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->respond(fn (): array => $service->queue(
            $v['filter'] ?? 'all', (int) ($v['limit'] ?? 30),
            isset($v['before_id']) ? (int) $v['before_id'] : null
        ));
    }

    private function respond(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
