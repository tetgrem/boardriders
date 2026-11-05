<?php

use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

// Разрешаем выполнение только в тестовой базе.
if (
    ! $app->environment('testing') ||
    DB::connection()->getDatabaseName() !== 'boardriders_test'
) {
    fwrite(STDERR, "Invalid test environment\n");
    exit(3);
}

if ($argc !== 8) {
    fwrite(STDERR, "Invalid arguments\n");
    exit(3);
}

$fromWarehouseId = (int) $argv[1];
$toWarehouseId = (int) $argv[2];
$variantId = (int) $argv[3];
$quantity = (int) $argv[4];
$reference = $argv[5];
$transferKey = $argv[6];
$gateFile = $argv[7];

// Сообщаем главному тесту, что процесс готов.
echo "READY\n";
fflush(STDOUT);

// Ждём, пока оба процесса будут готовы к старту.
$deadline = microtime(true) + 12;

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

    $transferId = $service->transfer(
        $fromWarehouseId,
        $toWarehouseId,
        $variantId,
        $quantity,
        $reference,
        $transferKey
    );

    echo "OK:{$transferId}\n";
    exit(0);
} catch (DomainException $exception) {
    echo "NO_STOCK\n";
    exit(2);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        get_class($exception) . ': '
        . $exception->getMessage() . "\n"
    );

    exit(3);
}
