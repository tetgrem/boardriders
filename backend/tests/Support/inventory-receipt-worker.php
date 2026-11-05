<?php

use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

if ($argc !== 7) {
    fwrite(STDERR, "Invalid arguments\n");
    exit(3);
}

// Запрещаем работу с основной БД.
if (
    ! $app->environment('testing') ||
    DB::connection()->getDatabaseName() !== 'boardriders_test'
) {
    fwrite(STDERR, "Invalid testing database\n");
    exit(3);
}

$warehouseId = (int) $argv[1];
$variantId = (int) $argv[2];
$quantity = (int) $argv[3];
$reference = $argv[4];
$receiptKey = $argv[5];
$gateFile = $argv[6];

echo "READY\n";
fflush(STDOUT);

// Ожидаем сигнал одновременного запуска.
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
    $inventory = $app->make(InventoryService::class);

    $stock = $inventory->receiveOnce(
        $warehouseId,
        $variantId,
        $quantity,
        $reference,
        $receiptKey
    );

    echo "OK:{$stock->id}\n";
    exit(0);

} catch (InvalidArgumentException $exception) {
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
