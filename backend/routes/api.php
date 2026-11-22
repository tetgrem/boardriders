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

// ============================================================
// BOARDRIDERS #55 — STALE MONO REFUND RECOVERY
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/payments/mono/refunds')
    ->group(function (): void {
        \Illuminate\Support\Facades\Route::get(
            '/executions/stale',
            [
                \App\Http\Controllers\Api\CheckoutMonoRefund55Controller::class,
                'stale',
            ]
        )
            ->middleware('throttle:20,1')
            ->name('mono55.stale');

        \Illuminate\Support\Facades\Route::post(
            '/executions/{executionId}/recover',
            [
                \App\Http\Controllers\Api\CheckoutMonoRefund55Controller::class,
                'recover',
            ]
        )
            ->whereNumber('executionId')
            ->middleware('throttle:5,1')
            ->name('mono55.recover');
    });

// BOARDRIDERS #56 — verified Mono refund journal
\Illuminate\Support\Facades\Route::middleware(['auth:sanctum'])
    ->prefix('v1/admin/payments/mono/refunds')
    ->group(function (): void {
        \Illuminate\Support\Facades\Route::post(
            '/executions/{executionId}/journal',
            [\App\Http\Controllers\Api\CheckoutMonoRefund56JournalController::class, 'record']
        )
            ->whereNumber('executionId')
            ->middleware('throttle:5,1')
            ->name('mono56.record');

        \Illuminate\Support\Facades\Route::get(
            '/journal/{journalId}',
            [\App\Http\Controllers\Api\CheckoutMonoRefund56JournalController::class, 'show']
        )
            ->whereNumber('journalId')
            ->middleware('throttle:30,1')
            ->name('mono56.show');
    });

// ============================================================
// BOARDRIDERS #58 — RMA INVENTORY POSTING
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/returns/inventory')
    ->group(function (): void {
        \Illuminate\Support\Facades\Route::post(
            '/journal/{journalId}/post',
            [
                \App\Http\Controllers\Api\CheckoutRmaInventory58Controller::class,
                'post',
            ]
        )
            ->whereNumber('journalId')
            ->middleware('throttle:5,1')
            ->name('rma58.post');

        \Illuminate\Support\Facades\Route::get(
            '/batches/{batchId}',
            [
                \App\Http\Controllers\Api\CheckoutRmaInventory58Controller::class,
                'show',
            ]
        )
            ->whereNumber('batchId')
            ->middleware('throttle:30,1')
            ->name('rma58.show');
    });

// ============================================================
// BOARDRIDERS #59 — OPERATIONAL RMA CLOSURE
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/returns/closure')
    ->group(function (): void {
        \Illuminate\Support\Facades\Route::post(
            '/journal/{journalId}/close',
            [
                \App\Http\Controllers\Api\CheckoutRmaClosure59Controller::class,
                'close',
            ]
        )
            ->whereNumber('journalId')
            ->middleware('throttle:5,1')
            ->name('rma59.close');

        \Illuminate\Support\Facades\Route::get(
            '/{closureId}',
            [
                \App\Http\Controllers\Api\CheckoutRmaClosure59Controller::class,
                'show',
            ]
        )
            ->whereNumber('closureId')
            ->middleware('throttle:30,1')
            ->name('rma59.show');
    });

// ============================================================
// BOARDRIDERS #60 — MONO REFUND FINANCIAL RECONCILIATION
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/payments/mono/refund-reconciliation')
    ->group(function (): void {
        \Illuminate\Support\Facades\Route::post(
            '/capture/{captureReceiptId}',
            [
                \App\Http\Controllers\Api\CheckoutMonoRefundReconciliation60Controller::class,
                'reconcile',
            ]
        )
            ->whereNumber('captureReceiptId')
            ->middleware('throttle:10,1')
            ->name('mono60.reconcile');

        \Illuminate\Support\Facades\Route::get(
            '/{reconciliationId}',
            [
                \App\Http\Controllers\Api\CheckoutMonoRefundReconciliation60Controller::class,
                'show',
            ]
        )
            ->whereNumber('reconciliationId')
            ->middleware('throttle:30,1')
            ->name('mono60.show');
    });

// ============================================================
// BOARDRIDERS #61 — FISCAL DOCUMENT PREPARATION & PREVIEW
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/fiscal61')
    ->group(function (): void {
        $controller = \App\Http\Controllers\Api\Fiscal61Controller::class;

        \Illuminate\Support\Facades\Route::post(
            '/sales/capture/{captureReceiptId}',
            [$controller, 'prepareSale']
        )
            ->whereNumber('captureReceiptId')
            ->middleware('throttle:10,1')
            ->name('fiscal61.sale.prepare');

        \Illuminate\Support\Facades\Route::post(
            '/refunds/journal/{journalId}',
            [$controller, 'prepareRefund']
        )
            ->whereNumber('journalId')
            ->middleware('throttle:10,1')
            ->name('fiscal61.refund.prepare');

        \Illuminate\Support\Facades\Route::get(
            '/documents',
            [$controller, 'index']
        )
            ->middleware('throttle:30,1')
            ->name('fiscal61.documents.index');

        \Illuminate\Support\Facades\Route::get(
            '/documents/{documentId}',
            [$controller, 'showDocument']
        )
            ->whereNumber('documentId')
            ->middleware('throttle:30,1')
            ->name('fiscal61.documents.show');

        \Illuminate\Support\Facades\Route::post(
            '/documents/{documentId}/preview',
            [$controller, 'requestPreview']
        )
            ->whereNumber('documentId')
            ->middleware('throttle:10,1')
            ->name('fiscal61.preview.queue');

        \Illuminate\Support\Facades\Route::get(
            '/documents/{documentId}/preview',
            [$controller, 'showPreview']
        )
            ->whereNumber('documentId')
            ->middleware('throttle:30,1')
            ->name('fiscal61.preview.show');

        \Illuminate\Support\Facades\Route::post(
            '/documents/{documentId}/incidents',
            [$controller, 'recordIncident']
        )
            ->whereNumber('documentId')
            ->middleware('throttle:10,1')
            ->name('fiscal61.incidents.store');

        \Illuminate\Support\Facades\Route::get(
            '/documents/{documentId}/incidents',
            [$controller, 'incidents']
        )
            ->whereNumber('documentId')
            ->middleware('throttle:30,1')
            ->name('fiscal61.incidents.index');

        \Illuminate\Support\Facades\Route::get(
            '/statistics',
            [$controller, 'statistics']
        )
            ->middleware('throttle:30,1')
            ->name('fiscal61.statistics');
    });


// ============================================================
// BOARDRIDERS #62 — CHECKBOX PRRO INTEGRATION
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/fiscal62')
    ->group(function (): void {
        $controller = \App\Http\Controllers\Api\Fiscal62Controller::class;

        \Illuminate\Support\Facades\Route::post(
            '/profiles/{variantId}',
            [$controller, 'profile']
        )
            ->whereNumber('variantId')
            ->middleware('throttle:10,1')
            ->name('fiscal62.profile.approve');

        \Illuminate\Support\Facades\Route::post(
            '/documents/{documentId}/prepare',
            [$controller, 'prepare']
        )
            ->whereNumber('documentId')
            ->middleware('throttle:10,1')
            ->name('fiscal62.ticket.prepare');

        \Illuminate\Support\Facades\Route::post(
            '/tickets/{ticketId}/send',
            [$controller, 'send']
        )
            ->whereNumber('ticketId')
            ->middleware('throttle:5,1')
            ->name('fiscal62.ticket.send');

        \Illuminate\Support\Facades\Route::post(
            '/tickets/{ticketId}/poll',
            [$controller, 'poll']
        )
            ->whereNumber('ticketId')
            ->middleware('throttle:15,1')
            ->name('fiscal62.ticket.poll');

        \Illuminate\Support\Facades\Route::get(
            '/tickets',
            [$controller, 'index']
        )
            ->middleware('throttle:30,1')
            ->name('fiscal62.ticket.index');

        \Illuminate\Support\Facades\Route::get(
            '/tickets/{ticketId}',
            [$controller, 'show']
        )
            ->whereNumber('ticketId')
            ->middleware('throttle:30,1')
            ->name('fiscal62.ticket.show');

        \Illuminate\Support\Facades\Route::get(
            '/statistics',
            [$controller, 'statistics']
        )
            ->middleware('throttle:30,1')
            ->name('fiscal62.statistics');
    });

// ============================================================
// BOARDRIDERS #63 — FISCAL INCIDENT MONITORING AND TRIAGE
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/fiscal63')
    ->group(function (): void {
        $controller = \App\Http\Controllers\Api\Fiscal63Controller::class;

        \Illuminate\Support\Facades\Route::post(
            '/scan',
            [$controller, 'scan']
        )
            ->middleware('throttle:5,1')
            ->name('fiscal63.scan');

        \Illuminate\Support\Facades\Route::get(
            '/dashboard',
            [$controller, 'dashboard']
        )
            ->middleware('throttle:30,1')
            ->name('fiscal63.dashboard');

        \Illuminate\Support\Facades\Route::get(
            '/cases',
            [$controller, 'index']
        )
            ->middleware('throttle:30,1')
            ->name('fiscal63.queue');

        \Illuminate\Support\Facades\Route::get(
            '/cases/{caseId}',
            [$controller, 'show']
        )
            ->whereNumber('caseId')
            ->middleware('throttle:30,1')
            ->name('fiscal63.show');

        \Illuminate\Support\Facades\Route::post(
            '/cases/{caseId}/acknowledge',
            [$controller, 'acknowledge']
        )
            ->whereNumber('caseId')
            ->middleware('throttle:10,1')
            ->name('fiscal63.ack');

        \Illuminate\Support\Facades\Route::post(
            '/cases/{caseId}/notes',
            [$controller, 'note']
        )
            ->whereNumber('caseId')
            ->middleware('throttle:20,1')
            ->name('fiscal63.note');

        \Illuminate\Support\Facades\Route::post(
            '/cases/{caseId}/recover',
            [$controller, 'recover']
        )
            ->whereNumber('caseId')
            ->middleware('throttle:5,1')
            ->name('fiscal63.recover');

        \Illuminate\Support\Facades\Route::post(
            '/cases/{caseId}/poll',
            [$controller, 'poll']
        )
            ->whereNumber('caseId')
            ->middleware('throttle:10,1')
            ->name('fiscal63.poll');

        \Illuminate\Support\Facades\Route::post(
            '/cases/{caseId}/close',
            [$controller, 'close']
        )
            ->whereNumber('caseId')
            ->middleware('throttle:10,1')
            ->name('fiscal63.close');
    });

// ============================================================
// BOARDRIDERS #64 — UNIFIED CRM API
// ============================================================

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/crm64')
    ->group(function (): void {
        $controller = \App\Http\Controllers\Api\Crm64Controller::class;

        Route::get('/dashboard', [$controller, 'dashboard'])
            ->name('crm64.dashboard');

        Route::get('/orders', [$controller, 'index'])
            ->name('crm64.orders.index');

        Route::get('/orders/{orderKey}', [$controller, 'show'])
            ->whereUuid('orderKey')
            ->name('crm64.orders.show');

        Route::get('/orders/{orderKey}/finance', [$controller, 'finance'])
            ->whereUuid('orderKey')
            ->name('crm64.orders.finance');

        Route::get('/orders/{orderKey}/timeline', [$controller, 'timeline'])
            ->whereUuid('orderKey')
            ->name('crm64.orders.timeline');

        Route::get('/orders/{orderKey}/access', [$controller, 'accessHistory'])
            ->whereUuid('orderKey')
            ->name('crm64.orders.access');

        Route::post('/orders/{orderKey}/notes', [$controller, 'note'])
            ->whereUuid('orderKey')
            ->name('crm64.orders.notes');
    });

// ============================================================
// BOARDRIDERS #65 — STAFF AUTHENTICATION
// ============================================================

\Illuminate\Support\Facades\Route::post(
    '/v1/admin/crm65/session',
    [
        \App\Http\Controllers\Api\Crm65SessionController::class,
        'login',
    ]
)
    ->middleware('throttle:8,1')
    ->name('crm65.login');

\Illuminate\Support\Facades\Route::middleware([
    'auth:sanctum',
])
    ->prefix('v1/admin/crm65')
    ->group(function (): void {
        $controller =
            \App\Http\Controllers\Api\Crm65SessionController::class;

        \Illuminate\Support\Facades\Route::get(
            '/me',
            [$controller, 'me']
        )
            ->middleware('throttle:60,1')
            ->name('crm65.me');

        \Illuminate\Support\Facades\Route::delete(
            '/session',
            [$controller, 'logout']
        )
            ->middleware('throttle:10,1')
            ->name('crm65.logout');
    });
