<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\NovaPoshtaDeliveryQuoteService;
use App\Services\Pricing\NovaPoshtaDestinationPreflightService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class NovaPoshtaDeliveryPreviewController extends Controller
{
    public function verify(
        Request $request,
        NovaPoshtaDestinationPreflightService $preflight,
    ): JsonResponse {
        $fields = $request->validate([
            'city_ref' => ['required', 'uuid'],
            'warehouse_ref' => ['required', 'uuid'],
            'type' => ['required', 'in:branch,parcel_locker'],
        ]);

        try {
            return response()->json([
                'data' => $preflight->inspect(
                    $fields['city_ref'],
                    $fields['warehouse_ref'],
                    $fields['type'],
                ),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => 'Invalid delivery destination'], 422);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Delivery provider temporarily unavailable',
            ], 503);
        }
    }

    public function quote(
        Request $request,
        NovaPoshtaDeliveryQuoteService $quotes,
    ): JsonResponse {
        $fields = $request->validate([
            'city_ref' => ['required', 'uuid'],
            'warehouse_ref' => ['required', 'uuid'],
            'type' => ['required', 'in:branch,parcel_locker'],
            'weight_grams' => ['required', 'integer', 'between:1,10000000'],
            'declared_value_minor' => ['required', 'integer', 'between:100,100000000'],
            'cod_amount_minor' => ['sometimes', 'integer', 'between:0,100000000'],
            'seats_amount' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        try {
            return response()->json([
                'data' => $quotes->estimate(
                    $fields['city_ref'],
                    $fields['warehouse_ref'],
                    $fields['type'],
                    (int) $fields['weight_grams'],
                    (int) $fields['declared_value_minor'],
                    (int) ($fields['cod_amount_minor'] ?? 0),
                    (int) ($fields['seats_amount'] ?? 1),
                ),
            ]);
        } catch (InvalidArgumentException | DomainException $exception) {
            return response()->json([
                'message' => 'Delivery quote cannot be calculated for these values',
            ], 422);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Delivery provider temporarily unavailable',
            ], 503);
        }
    }
}
