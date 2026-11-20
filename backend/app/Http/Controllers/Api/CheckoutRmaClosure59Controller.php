<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutRmaClosure59Service;
use DomainException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class CheckoutRmaClosure59Controller extends Controller
{
    public function close(
        int $journalId,
        CheckoutRmaClosure59Service $service
    ): JsonResponse {
        try {
            return response()->json(
                $service->close($journalId),
                201
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'RMA closure audit verification failed',
            ], 503);
        }
    }

    public function show(
        int $closureId,
        CheckoutRmaClosure59Service $service
    ): JsonResponse {
        try {
            return response()->json(
                $service->show($closureId)
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'RMA closure audit verification failed',
            ], 503);
        }
    }
}
