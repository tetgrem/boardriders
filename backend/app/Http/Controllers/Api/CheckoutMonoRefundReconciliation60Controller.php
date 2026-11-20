<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutMonoRefundReconciliation60Service;
use DomainException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class CheckoutMonoRefundReconciliation60Controller extends Controller
{
    public function reconcile(
        int $captureReceiptId,
        CheckoutMonoRefundReconciliation60Service $service
    ): JsonResponse {
        try {
            return response()->json($service->reconcile($captureReceiptId), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => 'Mono refund reconciliation audit failed'], 503);
        }
    }

    public function show(
        int $reconciliationId,
        CheckoutMonoRefundReconciliation60Service $service
    ): JsonResponse {
        try {
            return response()->json($service->show($reconciliationId));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => 'Mono refund reconciliation audit failed'], 503);
        }
    }
}
