<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Promotions\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        PromotionService $promotions
    ): JsonResponse {

        // ========================================
        // ПЕРЕВІРКА ВХІДНИХ ДАНИХ
        // ========================================

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'min:3',
                'max:40',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]{2,39}$/',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:200',
            ],

            'items.*.variant_id' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'between:1,99',
            ],
        ]);

        // ========================================
        // РОЗРАХУНОК ЧЕРЕЗ PROMOTION SERVICE
        // ========================================

        $result = $promotions->preview(
            $data['code'],
            $data['items'],
            $request->user()?->id
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
