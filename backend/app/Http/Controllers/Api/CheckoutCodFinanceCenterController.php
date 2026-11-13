<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodFinanceCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodFinanceCenterController extends Controller
{
    public function queue(
        Request $request,
        CheckoutCodFinanceCenterService $center
    ): JsonResponse {
        $v = $request->validate([
            'source' => [
                'sometimes',
                'in:all,case,case_alert,monitor,sla',
            ],
            'severity' => [
                'sometimes',
                'in:all,critical,high,warning',
            ],
            'unacknowledged' => [
                'sometimes',
                'boolean',
            ],
            'limit' => [
                'sometimes',
                'integer',
                'between:1,100',
            ],
            'cursor' => [
                'sometimes',
                'string',
                'max:1024',
            ],
        ]);

        try {
            return response()->json(
                $center->queue(
                    $v['source'] ?? 'all',
                    $v['severity'] ?? 'all',
                    (bool) ($v['unacknowledged'] ?? false),
                    isset($v['limit']) ? (int) $v['limit'] : 20,
                    $v['cursor'] ?? null
                )
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function summary(
        CheckoutCodFinanceCenterService $center
    ): JsonResponse {
        return response()->json(
            $center->summary()
        );
    }

    public function overview(
        Request $request,
        CheckoutCodFinanceCenterService $center
    ): JsonResponse {
        $v = $request->validate([
            'top' => [
                'sometimes',
                'integer',
                'between:1,50',
            ],
        ]);

        return response()->json(
            $center->overview(
                isset($v['top']) ? (int) $v['top'] : 10
            )
        );
    }
}
