<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pricing\CheckoutCodDailyMonitorService;
use Illuminate\Console\Command;

final class CheckoutCodDailyMonitorCommand extends Command
{
    protected $signature = 'cod:daily-monitor {--limit=50 : Sealed daily reports per run}';
    protected $description = 'Check signed COD daily reports for uncovered late financial evidence';

    public function handle(CheckoutCodDailyMonitorService $monitor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 200) {
            $this->error('COD monitoring limit must be an integer from 1 to 200');
            return self::FAILURE;
        }
        $this->line(json_encode($monitor->scan($limit), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
