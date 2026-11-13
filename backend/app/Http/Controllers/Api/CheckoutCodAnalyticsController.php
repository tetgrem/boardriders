<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodAnalyticsController extends Controller
{
    public function __invoke(Request $request, CheckoutCodAnalyticsService $analytics): JsonResponse
    {
        $v = $request->validate([
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);

        try {
            return response()->json($analytics->report(
                $v['from'] ?? null,
                $v['to'] ?? null
            ));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
