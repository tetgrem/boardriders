<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutRmaInventory58Service;
use DomainException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class CheckoutRmaInventory58Controller extends Controller
{
    public function post(
        int $journalId,
        CheckoutRmaInventory58Service $service
    ): JsonResponse {
        try {
            return response()->json(
                $service->post($journalId),
                201
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'RMA inventory audit verification failed',
            ], 503);
        }
    }

    public function show(
        int $batchId,
        CheckoutRmaInventory58Service $service
    ): JsonResponse {
        try {
            return response()->json(
                $service->show($batchId)
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'RMA inventory audit verification failed',
            ], 503);
        }
    }
}
