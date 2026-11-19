<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutMonoRefund56JournalService;
use DomainException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class CheckoutMonoRefund56JournalController extends Controller
{
    public function record(
        int $executionId,
        CheckoutMonoRefund56JournalService $service,
    ): JsonResponse {
        try {
            return response()->json($service->record($executionId), 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Refund journal integrity check failed'], 503);
        }
    }

    public function show(
        int $journalId,
        CheckoutMonoRefund56JournalService $service,
    ): JsonResponse {
        try {
            return response()->json($service->show($journalId));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Refund journal integrity check failed'], 503);
        }
    }
}
