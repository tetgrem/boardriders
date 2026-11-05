<?php

use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OrderInventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($argc !== 4) {
    fwrite(STDERR, "Expected operation, key, gate path\n");
    exit(3);
}

if (
    ! $app->environment('testing')
    || config('database.default') !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'boardriders_test'
) {
    fwrite(STDERR, "Refusing to use a non-test database\n");
    exit(3);
}

[$script, $operation, $key, $gateFile] = $argv;

if (! in_array($operation, ['pay', 'expire'], true)) {
    fwrite(STDERR, "Unknown operation\n");
    exit(3);
}

echo "READY\n";
fflush(STDOUT);

$deadline = microtime(true) + 15;

while (! is_file($gateFile)) {
    clearstatcache(true, $gateFile);

    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Gate timeout\n");
        exit(3);
    }

    usleep(10000);
}

try {
    if ($operation === 'pay') {
        $status = $app->make(OrderInventoryService::class)
            ->registerCapturedPayment($key);

        echo "PAY:{$status}\n";
    } else {
        $released = $app->make(InventoryService::class)
            ->expireOrderReservation($key);

        echo 'EXPIRE:' . ($released ? '1' : '0') . "\n";
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        get_class($exception) . ': ' . $exception->getMessage() . "\n"
    );
    exit(3);
}
