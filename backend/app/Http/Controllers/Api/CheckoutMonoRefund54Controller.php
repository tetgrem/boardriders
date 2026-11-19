<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutMonoRefund54Service;
use DomainException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class CheckoutMonoRefund54Controller extends Controller
{
    public function send(
        int $planId,
        CheckoutMonoRefund54Service $service
    ): JsonResponse {
        return $this->respond(
            fn (): array => $service->send($planId),
            202
        );
    }

    public function reconcile(
        int $executionId,
        CheckoutMonoRefund54Service $service
    ): JsonResponse {
        return $this->respond(
            fn (): array => $service->reconcile($executionId)
        );
    }

    public function show(
        int $executionId,
        CheckoutMonoRefund54Service $service
    ): JsonResponse {
        return $this->respond(
            fn (): array => $service->show($executionId)
        );
    }

    private function respond(
        callable $action,
        int $success = 200
    ): JsonResponse {
        try {
            return response()->json(
                $action(),
                $success
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (RuntimeException) {
            return response()->json([
                'message' =>
                    'Refund reconciliation or integrity check failed',
            ], 503);
        }
    }
}
