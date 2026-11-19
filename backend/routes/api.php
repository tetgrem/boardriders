<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ProductCatalogController;
use App\Http\Controllers\Api\CartPreviewController;
use App\Http\Controllers\Api\PromotionPreviewController;
use App\Http\Controllers\Api\LoyaltyWalletPreviewController;
use App\Http\Controllers\Api\NovaPoshtaDirectoryController;
use App\Http\Controllers\Api\NovaPoshtaDeliveryPreviewController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\CheckoutCodNovaPayRegisterCsvImportController;

use App\Http\Controllers\Api\CheckoutCodBankEvidenceController;

use App\Http\Controllers\Api\CheckoutCodFinancialGatewayController;


Route::prefix('v1/catalog')->group(function () {
    Route::get('/variants', [CatalogController::class, 'index']);
    Route::get('/variants/{sku}', [CatalogController::class, 'show']);
    Route::get('/products', [ProductCatalogController::class, 'index']);
    Route::get('/products/{slug}', [ProductCatalogController::class, 'show']);
    Route::get('/brands', [ProductCatalogController::class, 'brands']);
});

Route::prefix('v1/cart')->group(function () {
    Route::post('/preview', CartPreviewController::class)
        ->middleware('throttle:60,1');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/health', function () {
    try {
        DB::select('SELECT 1');

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

Route::prefix('v1/promotions')->group(function () {
    Route::post('/preview', PromotionPreviewController::class)
        ->middleware('throttle:30,1');
});

Route::get('/v1/loyalty/wallet/preview', LoyaltyWalletPreviewController::class)
    ->middleware(['auth:sanctum', 'throttle:60,1']);

Route::prefix('v1/delivery/nova-poshta')->group(function () {
    Route::get('/settlements', [NovaPoshtaDirectoryController::class, 'settlements'])
        ->middleware('throttle:30,1');

    Route::get('/warehouses', [NovaPoshtaDirectoryController::class, 'warehouses'])
        ->middleware('throttle:30,1');

    Route::post('/verify', [NovaPoshtaDeliveryPreviewController::class, 'verify'])
        ->middleware('throttle:10,1');

    Route::post('/quote', [NovaPoshtaDeliveryPreviewController::class, 'quote'])
        ->middleware('throttle:10,1');
});


Route::post(
    '/v1/cod/financial-gateway/ingest',
    CheckoutCodFinancialGatewayController::class
)->middleware('throttle:30,1');


Route::post(
    '/v1/cod/bank-evidence/ingest',
    CheckoutCodBankEvidenceController::class
)->middleware('throttle:30,1');


Route::post(
    '/v1/admin/cod/novapay/register/csv-import',
    CheckoutCodNovaPayRegisterCsvImportController::class
)
    ->middleware(['auth:sanctum', 'throttle:5,1'])
    ->name('cod.novapay.register.csv-import');


// ============================================================
// BOARDRIDERS BLOCK 41 — COD FINANCIAL OPERATIONS
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/v1/admin/cod/operations',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsController::class,
            'index',
        ]
    )->middleware('throttle:30,1');

    \Illuminate\Support\Facades\Route::get(
        '/v1/admin/cod/operations/{postingKey}',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsController::class,
            'show',
        ]
    )->middleware('throttle:30,1');

    \Illuminate\Support\Facades\Route::post(
        '/v1/admin/cod/operations/{postingKey}/reviews',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsController::class,
            'review',
        ]
    )->middleware('throttle:10,1');

});


// ============================================================
// BOARDRIDERS BLOCK 42 — COD OPERATIONS CASE WORKFLOW
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/v1/admin/cod/operation-cases',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsCaseController::class,
            'index',
        ]
    )->middleware('throttle:30,1');

    \Illuminate\Support\Facades\Route::post(
        '/v1/admin/cod/operations/{postingKey}/cases',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsCaseController::class,
            'open',
        ]
    )->middleware('throttle:10,1');

    \Illuminate\Support\Facades\Route::get(
        '/v1/admin/cod/operation-cases/{caseKey}',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsCaseController::class,
            'show',
        ]
    )->middleware('throttle:30,1');

    \Illuminate\Support\Facades\Route::get(
        '/v1/admin/cod/operation-cases/{caseKey}/history',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsCaseController::class,
            'history',
        ]
    )->middleware('throttle:30,1');

    \Illuminate\Support\Facades\Route::post(
        '/v1/admin/cod/operation-cases/{caseKey}/actions',
        [
            \App\Http\Controllers\Api\CheckoutCodOperationsCaseController::class,
            'action',
        ]
    )->middleware('throttle:10,1');
});


// ============================================================
// BOARDRIDERS BLOCK 43 — CRM COD SLA ALERT INBOX
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/v1/admin/cod/case-alerts',
        [
            \App\Http\Controllers\Api\CheckoutCodCaseAlertController::class,
            'index',
        ]
    )->middleware('throttle:30,1');

    \Illuminate\Support\Facades\Route::post(
        '/v1/admin/cod/case-alerts/{notificationId}/acknowledge',
        [
            \App\Http\Controllers\Api\CheckoutCodCaseAlertController::class,
            'acknowledge',
        ]
    )->whereNumber('notificationId')
        ->middleware('throttle:20,1');
});


// ============================================================
// BOARDRIDERS BLOCK 44 — COD MANAGEMENT ANALYTICS
// ============================================================

\Illuminate\Support\Facades\Route::get(
    '/v1/admin/cod/analytics',
    \App\Http\Controllers\Api\CheckoutCodAnalyticsController::class
)
    ->middleware([
        'auth:sanctum',
        'throttle:20,1',
    ])
    ->name('cod.analytics.dashboard');


// ============================================================
// BOARDRIDERS BLOCK 45 — DAILY IMMUTABLE COD REPORTS
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/cod/daily-reports')->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyReportController::class,
            'index',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.daily45.index');

    \Illuminate\Support\Facades\Route::post(
        '/',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyReportController::class,
            'seal',
        ]
    )->middleware('throttle:5,1')
        ->name('cod.daily45.seal');

    \Illuminate\Support\Facades\Route::get(
        '/{reportKey}/verify',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyReportController::class,
            'verify',
        ]
    )->whereUuid('reportKey')
        ->middleware('throttle:10,1')
        ->name('cod.daily45.verify');

    \Illuminate\Support\Facades\Route::get(
        '/{reportKey}/events',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyReportController::class,
            'events',
        ]
    )->whereUuid('reportKey')
        ->middleware('throttle:30,1')
        ->name('cod.daily45.events');

    \Illuminate\Support\Facades\Route::post(
        '/{reportKey}/export.csv',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyReportController::class,
            'csv',
        ]
    )->whereUuid('reportKey')
        ->middleware('throttle:5,1')
        ->name('cod.daily45.csv');

    \Illuminate\Support\Facades\Route::get(
        '/{reportKey}',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyReportController::class,
            'show',
        ]
    )->whereUuid('reportKey')
        ->middleware('throttle:30,1')
        ->name('cod.daily45.show');
});


// ============================================================
// BOARDRIDERS BLOCK 46 — COD DAILY REPORT CORRECTIONS
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/cod')->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/daily-reports/{reportKey}/corrections',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyCorrectionController::class,
            'index',
        ]
    )->whereUuid('reportKey')
        ->middleware('throttle:30,1')
        ->name('cod.correction46.index');

    \Illuminate\Support\Facades\Route::post(
        '/daily-reports/{reportKey}/corrections',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyCorrectionController::class,
            'propose',
        ]
    )->whereUuid('reportKey')
        ->middleware('throttle:5,1')
        ->name('cod.correction46.propose');

    \Illuminate\Support\Facades\Route::get(
        '/corrections/{correctionKey}/verify',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyCorrectionController::class,
            'verify',
        ]
    )->whereUuid('correctionKey')
        ->middleware('throttle:10,1')
        ->name('cod.correction46.verify');

    \Illuminate\Support\Facades\Route::get(
        '/corrections/{correctionKey}/events',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyCorrectionController::class,
            'events',
        ]
    )->whereUuid('correctionKey')
        ->middleware('throttle:30,1')
        ->name('cod.correction46.events');

    \Illuminate\Support\Facades\Route::post(
        '/corrections/{correctionKey}/actions',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyCorrectionController::class,
            'act',
        ]
    )->whereUuid('correctionKey')
        ->middleware('throttle:10,1')
        ->name('cod.correction46.act');

    \Illuminate\Support\Facades\Route::post(
        '/corrections/{correctionKey}/export.csv',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyCorrectionController::class,
            'csv',
        ]
    )->whereUuid('correctionKey')
        ->middleware('throttle:5,1')
        ->name('cod.correction46.csv');

    \Illuminate\Support\Facades\Route::get(
        '/corrections/{correctionKey}',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyCorrectionController::class,
            'show',
        ]
    )->whereUuid('correctionKey')
        ->middleware('throttle:30,1')
        ->name('cod.correction46.show');
});


// ============================================================
// BOARDRIDERS BLOCK 47 — DAILY COD MONITORING
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/cod/daily-monitor')->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyMonitorController::class,
            'index',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.monitor47.index');

    \Illuminate\Support\Facades\Route::get(
        '/reports/{reportKey}/history',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyMonitorController::class,
            'history',
        ]
    )->whereUuid('reportKey')
        ->middleware('throttle:30,1')
        ->name('cod.monitor47.history');

    \Illuminate\Support\Facades\Route::post(
        '/signals/{signalId}/acknowledge',
        [
            \App\Http\Controllers\Api\CheckoutCodDailyMonitorController::class,
            'acknowledge',
        ]
    )->whereNumber('signalId')
        ->middleware('throttle:20,1')
        ->name('cod.monitor47.acknowledge');
});

// ============================================================
// BOARDRIDERS BLOCK 48 — FINANCIAL CORRECTION SLA
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/cod/correction-sla')->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/',
        [
            \App\Http\Controllers\Api\CheckoutCodCorrectionSlaController::class,
            'index',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.sla48.index');

    \Illuminate\Support\Facades\Route::get(
        '/summary',
        [
            \App\Http\Controllers\Api\CheckoutCodCorrectionSlaController::class,
            'summary',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.sla48.summary');

    \Illuminate\Support\Facades\Route::get(
        '/corrections/{correctionKey}/history',
        [
            \App\Http\Controllers\Api\CheckoutCodCorrectionSlaController::class,
            'history',
        ]
    )->whereUuid('correctionKey')
        ->middleware('throttle:30,1')
        ->name('cod.sla48.history');

    \Illuminate\Support\Facades\Route::post(
        '/alerts/{alertId}/acknowledge',
        [
            \App\Http\Controllers\Api\CheckoutCodCorrectionSlaController::class,
            'acknowledge',
        ]
    )->whereNumber('alertId')
        ->middleware('throttle:20,1')
        ->name('cod.sla48.acknowledge');
});

// ============================================================
// BOARDRIDERS BLOCK 49 — UNIFIED COD FINANCE CENTER
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/cod/finance-center')->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/queue',
        [
            \App\Http\Controllers\Api\CheckoutCodFinanceCenterController::class,
            'queue',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.finance49.queue');

    \Illuminate\Support\Facades\Route::get(
        '/summary',
        [
            \App\Http\Controllers\Api\CheckoutCodFinanceCenterController::class,
            'summary',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.finance49.summary');

    \Illuminate\Support\Facades\Route::get(
        '/overview',
        [
            \App\Http\Controllers\Api\CheckoutCodFinanceCenterController::class,
            'overview',
        ]
    )->middleware('throttle:10,1')
        ->name('cod.finance49.overview');
});

// ============================================================
// BOARDRIDERS BLOCK 50 — INTERNAL COD SETTLEMENT ALLOCATION SEALS
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/cod/settlement-seals')->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/preview',
        [
            \App\Http\Controllers\Api\CheckoutCodSettlementSealController::class,
            'preview',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.seal50.preview');

    \Illuminate\Support\Facades\Route::post(
        '/',
        [
            \App\Http\Controllers\Api\CheckoutCodSettlementSealController::class,
            'seal',
        ]
    )->middleware('throttle:5,1')
        ->name('cod.seal50.create');

    \Illuminate\Support\Facades\Route::get(
        '/{sealKey}',
        [
            \App\Http\Controllers\Api\CheckoutCodSettlementSealController::class,
            'show',
        ]
    )->whereUuid('sealKey')
        ->middleware('throttle:30,1')
        ->name('cod.seal50.show');
});

// ============================================================
// BOARDRIDERS BLOCK 51 — NOVA POSHTA TTN LIFECYCLE
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/cod/ttn-lifecycle')->group(function (): void {

    \Illuminate\Support\Facades\Route::get(
        '/queue',
        [
            \App\Http\Controllers\Api\CheckoutCodTtnLifecycleController::class,
            'queue',
        ]
    )->middleware('throttle:30,1')
        ->name('cod.lifecycle51.queue');

    \Illuminate\Support\Facades\Route::get(
        '/{trackingKey}',
        [
            \App\Http\Controllers\Api\CheckoutCodTtnLifecycleController::class,
            'status',
        ]
    )->whereUuid('trackingKey')
        ->middleware('throttle:30,1')
        ->name('cod.lifecycle51.status');

    \Illuminate\Support\Facades\Route::get(
        '/{trackingKey}/history',
        [
            \App\Http\Controllers\Api\CheckoutCodTtnLifecycleController::class,
            'history',
        ]
    )->whereUuid('trackingKey')
        ->middleware('throttle:30,1')
        ->name('cod.lifecycle51.history');

    \Illuminate\Support\Facades\Route::post(
        '/{trackingKey}/sync',
        [
            \App\Http\Controllers\Api\CheckoutCodTtnLifecycleController::class,
            'sync',
        ]
    )->whereUuid('trackingKey')
        ->middleware('throttle:10,1')
        ->name('cod.lifecycle51.sync');
});

// ============================================================
// BOARDRIDERS BLOCK 52 — OMS RETURNS / RMA
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])->prefix('v1/admin/orders/returns')->group(function (): void {

    \Illuminate\Support\Facades\Route::post(
        '/cancel-pre-dispatch',
        [\App\Http\Controllers\Api\CheckoutOrderReturnController::class, 'cancel']
    )->middleware('throttle:5,1')
        ->name('cod.return52.cancel');

    \Illuminate\Support\Facades\Route::post(
        '/cases',
        [\App\Http\Controllers\Api\CheckoutOrderReturnController::class, 'open']
    )->middleware('throttle:10,1')
        ->name('cod.return52.open');

    \Illuminate\Support\Facades\Route::get(
        '/queue',
        [\App\Http\Controllers\Api\CheckoutOrderReturnController::class, 'queue']
    )->middleware('throttle:30,1')
        ->name('cod.return52.queue');

    \Illuminate\Support\Facades\Route::get(
        '/cases/{returnKey}',
        [\App\Http\Controllers\Api\CheckoutOrderReturnController::class, 'show']
    )->whereUuid('returnKey')
        ->middleware('throttle:30,1')
        ->name('cod.return52.show');

    \Illuminate\Support\Facades\Route::post(
        '/cases/{returnKey}/inspection',
        [\App\Http\Controllers\Api\CheckoutOrderReturnController::class, 'inspect']
    )->whereUuid('returnKey')
        ->middleware('throttle:10,1')
        ->name('cod.return52.inspect');

    \Illuminate\Support\Facades\Route::post(
        '/cases/{returnKey}/decision',
        [\App\Http\Controllers\Api\CheckoutOrderReturnController::class, 'decide']
    )->whereUuid('returnKey')
        ->middleware('throttle:10,1')
        ->name('cod.return52.decide');
});

// ============================================================
// BOARDRIDERS #53 — MONOBANK ACQUIRING
// ============================================================

\Illuminate\Support\Facades\Route::post(
    '/v1/payments/mono/webhook',
    [
        \App\Http\Controllers\Api\CheckoutMono53Controller::class,
        'webhook',
    ]
)
    ->middleware('throttle:60,1')
    ->name('mono53.webhook');

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/payments/mono')
    ->group(function (): void {
        \Illuminate\Support\Facades\Route::post(
            '/invoices',
            [
                \App\Http\Controllers\Api\CheckoutMono53Controller::class,
                'start',
            ]
        )
            ->middleware('throttle:5,1')
            ->name('mono53.start');

        \Illuminate\Support\Facades\Route::post(
            '/invoices/{invoiceId}/reconcile',
            [
                \App\Http\Controllers\Api\CheckoutMono53Controller::class,
                'reconcile',
            ]
        )
            ->middleware('throttle:10,1')
            ->name('mono53.reconcile');

        \Illuminate\Support\Facades\Route::post(
            '/returns/{returnKey}/plan',
            [
                \App\Http\Controllers\Api\CheckoutMono53Controller::class,
                'planRefund',
            ]
        )
            ->whereUuid('returnKey')
            ->middleware('throttle:10,1')
            ->name('mono53.refund.plan');
    });

// ============================================================
// BOARDRIDERS #54 — MONO REFUND EXECUTION
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/payments/mono/refunds')
    ->group(function (): void {
        \Illuminate\Support\Facades\Route::post(
            '/plans/{planId}/send',
            [
                \App\Http\Controllers\Api\CheckoutMonoRefund54Controller::class,
                'send',
            ]
        )
            ->whereNumber('planId')
            ->middleware('throttle:3,1')
            ->name('mono54.send');

        \Illuminate\Support\Facades\Route::post(
            '/executions/{executionId}/reconcile',
            [
                \App\Http\Controllers\Api\CheckoutMonoRefund54Controller::class,
                'reconcile',
            ]
        )
            ->whereNumber('executionId')
            ->middleware('throttle:10,1')
            ->name('mono54.reconcile');

        \Illuminate\Support\Facades\Route::get(
            '/executions/{executionId}',
            [
                \App\Http\Controllers\Api\CheckoutMonoRefund54Controller::class,
                'show',
            ]
        )
            ->whereNumber('executionId')
            ->middleware('throttle:30,1')
            ->name('mono54.show');
    });
