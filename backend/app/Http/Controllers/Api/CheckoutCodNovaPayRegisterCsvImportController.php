<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodNovaPayRegisterCsvImportService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Authenticated staff upload. Bytes are hashed/parsed, never logged or persisted raw. */
final class CheckoutCodNovaPayRegisterCsvImportController extends Controller
{
    public function __invoke(
        Request $request,
        CheckoutCodNovaPayRegisterCsvImportService $service
    ): JsonResponse {
        $validated = $request->validate([
            'posting_key' => ['required', 'uuid'],
            'operation_key' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:512'],
        ]);

        $upload = $request->file('file');
        $path = $upload?->getRealPath();
        $bytes = is_string($path) ? file_get_contents($path) : false;

        if (!is_string($bytes)) {
            return response()->json(['message' => 'CSV upload unavailable'], 422);
        }

        try {
            $result = $service->import(
                $validated['posting_key'],
                $validated['operation_key'],
                $bytes
            );

            return response()->json(
                $result,
                $result['idempotent_replay'] ? 200 : 201
            );
        } catch (AuthorizationException $e) {
            return response()->json(
                ['message' => 'NovaPay register import not permitted'],
                403
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(
                ['message' => 'Invalid normalized CSV register'],
                422
            );
        } catch (DomainException $e) {
            return response()->json(
                ['message' => 'NovaPay register cannot be captured'],
                409
            );
        }
    }
}
