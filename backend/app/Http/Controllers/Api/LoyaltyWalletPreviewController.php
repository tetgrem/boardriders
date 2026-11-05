<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Loyalty\LoyaltyWalletPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoyaltyWalletPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        LoyaltyWalletPreviewService $preview
    ): JsonResponse {
        $data = $request->validate([
            'market_code' => [
                'required',
                'string',
                'max:16',
                'regex:/^[A-Z][A-Z0-9_]{1,15}$/',
            ],
        ]);

        // auth:sanctum у маршруті гарантує перевірену ідентичність.
        // Не приймаємо user_id, wallet_id або account_id із запиту.
        $result = $preview->forVerifiedUser(
            (int) $request->user()->id,
            $data['market_code']
        );

        if ($result === null) {
            return response()->json([
                'message' => 'Market is unavailable',
            ], 404);
        }

        return response()->json([
            'data' => $result,
        ]);
    }
}
