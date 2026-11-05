<?php

use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

// Захист основної бази від тестових операцій.
if (
    ! $app->environment('testing') ||
    DB::connection()->getDatabaseName() !== 'boardriders_test'
) {
    fwrite(STDERR, "Invalid testing environment\n");
    exit(3);
}

if ($argc !== 6) {
    fwrite(STDERR, "Invalid arguments\n");
    exit(3);
}

$warehouseId = (int) $argv[1];
$variantId = (int) $argv[2];
$quantity = (int) $argv[3];
$reference = $argv[4];
$reservationKey = $argv[5];

// Повідомляємо тесту, що процес готовий.
echo "READY\n";
fflush(STDOUT);

try {
    $service = $app->make(InventoryService::class);

    $service->reserveForOrder(
        $warehouseId,
        $variantId,
        $quantity,
        $reference,
        $reservationKey
    );

    echo "OK\n";
    exit(0);
} catch (DomainException $exception) {
    echo "NO_STOCK\n";
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(3);
}
