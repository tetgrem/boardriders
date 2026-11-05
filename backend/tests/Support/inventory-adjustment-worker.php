<?php

use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

// Защита от случайного запуска на основной базе.
if (
    ! $app->environment('testing') ||
    DB::connection()->getDatabaseName() !== 'boardriders_test'
) {
    fwrite(STDERR, "Invalid testing environment\n");
    exit(3);
}

if ($argc !== 8) {
    fwrite(STDERR, "Invalid arguments\n");
    exit(3);
}

$warehouseId = (int) $argv[1];
$variantId = (int) $argv[2];
$countedQty = (int) $argv[3];
$reference = $argv[4];
$adjustmentKey = $argv[5];
$expectedRevision = (int) $argv[6];
$gateFile = $argv[7];

echo "READY\n";
fflush(STDOUT);

// Ожидаем общего сигнала от главного теста.
$deadline = microtime(true) + 15;

while (!is_file($gateFile)) {
    clearstatcache(true, $gateFile);

    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Start gate timeout\n");
        exit(3);
    }

    usleep(10000);
}

try {
    $service = $app->make(InventoryService::class);

    $adjustmentId = $service->reconcileStockCount(
        $warehouseId,
        $variantId,
        $countedQty,
        $reference,
        $adjustmentKey,
        $expectedRevision,
        'stocktake'
    );

    echo "OK:{$adjustmentId}\n";
    exit(0);
} catch (DomainException $exception) {
    echo "REJECTED: {$exception->getMessage()}\n";
    exit(2);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        get_class($exception) . ': '
        . $exception->getMessage() . "\n"
    );

    exit(3);
}
