<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fiscal63\Fiscal63MonitorService;
use Illuminate\Console\Command;
use Throwable;

final class Fiscal63ScanCommand extends Command
{
    protected $signature =
        'fiscal63:scan {--limit=100 : Maximum new incident candidates}';

    protected $description =
        'Scan fiscal delivery incidents without contacting the provider';

    public function handle(
        Fiscal63MonitorService $monitor
    ): int {
        try {
            $result = $monitor->scanSystem(
                (int) $this->option('limit')
            );

            $this->line(
                json_encode(
                    $result,
                    JSON_THROW_ON_ERROR
                )
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->error(
                'Fiscal63 monitoring failed: '
                . $exception->getMessage()
            );

            return self::FAILURE;
        }
    }
}
