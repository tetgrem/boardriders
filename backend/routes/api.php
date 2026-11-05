<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ProductCatalogController;
use App\Http\Controllers\Api\CartPreviewController;
use App\Http\Controllers\Api\PromotionPreviewController;
use App\Http\Controllers\Api\LoyaltyWalletPreviewController;


Route::prefix('v1/catalog')->group(function () {
    Route::get('/variants', [
        CatalogController::class,
        'index',
    ]);

    Route::get('/variants/{sku}', [
        CatalogController::class,
        'show',
    ]);
    Route::get('/products', [
        ProductCatalogController::class,
        'index',
    ]);

    Route::get('/products/{slug}', [
        ProductCatalogController::class,
        'show',
    ]);

    Route::get('/brands', [
        ProductCatalogController::class,
        'brands',
    ]);
});

Route::prefix('v1/cart')->group(function () {
    Route::post('/preview', CartPreviewController::class)
        ->middleware('throttle:60,1');
});


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Development health check. Replace with a more restrictive check before production.
\Illuminate\Support\Facades\Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::select('SELECT 1');

        return response()->json([
            'service' => 'boardriders-api',
            'status' => 'ok',
            'database' => 'connected',
        ]);
    } catch (\Throwable $exception) {
        report($exception);

        return response()->json([
            'service' => 'boardriders-api',
            'status' => 'error',
            'database' => 'unavailable',
        ], 503);
    }
});

// ========================================
// BOARDRIDERS — PROMOTIONS API
// ========================================

Route::prefix('v1/promotions')->group(function () {

    Route::post(
        '/preview',
        PromotionPreviewController::class
    )->middleware('throttle:30,1');

});

// Read-only бонусний баланс: виключно для авторизованого користувача.
Route::get('/v1/loyalty/wallet/preview', LoyaltyWalletPreviewController::class)
    ->middleware(['auth:sanctum', 'throttle:60,1']);
