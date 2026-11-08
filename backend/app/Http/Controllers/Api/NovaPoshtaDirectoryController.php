<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\NovaPoshtaDirectoryService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class NovaPoshtaDirectoryController extends Controller
{
    public function settlements(
        Request $request,
        NovaPoshtaDirectoryService $directory,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $items = $directory->settlements(
                $validated['q'],
                (int) ($validated['limit'] ?? 20),
            );

            return response()->json([
                'data' => $items,
                'meta' => [
                    'count' => count($items),
                    'source' => 'nova_poshta',
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'Invalid settlement search',
            ], 422);
        } catch (RuntimeException | DomainException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Delivery directory temporarily unavailable',
            ], 503);
        }
    }

    public function warehouses(
        Request $request,
        NovaPoshtaDirectoryService $directory,
    ): JsonResponse {
        $validated = $request->validate([
            'city_ref' => ['required', 'uuid'],
            'type' => ['sometimes', 'in:all,branch,parcel_locker'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $items = $directory->warehouses(
                $validated['city_ref'],
                $validated['type'] ?? 'all',
                (int) ($validated['page'] ?? 1),
                (int) ($validated['limit'] ?? 50),
            );

            return response()->json([
                'data' => $items,
                'meta' => [
                    'count' => count($items),
                    'page' => (int) ($validated['page'] ?? 1),
                    'limit' => (int) ($validated['limit'] ?? 50),
                    'source' => 'nova_poshta',
                    'pagination_scope' => 'provider_page',
                    'cod_eligibility_confirmed' => false,
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'Invalid warehouse filters',
            ], 422);
        } catch (RuntimeException | DomainException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Delivery directory temporarily unavailable',
            ], 503);
        }
    }
}
