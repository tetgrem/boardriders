<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutMonoRefund54Service;
use DomainException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class CheckoutMonoRefund55Controller extends Controller
{
    public function stale(
        CheckoutMonoRefund54Service $service
    ): JsonResponse {
        return response()->json([
            'items' => $service->staleExecutions(),
        ]);
    }

    public function recover(
        int $executionId,
        CheckoutMonoRefund54Service $service
    ): JsonResponse {
        try {
            return response()->json(
                $service->recoverStale($executionId)
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (RuntimeException) {
            return response()->json([
                'message' =>
                    'Mono refund recovery integrity check failed',
            ], 503);
        }
    }
}
