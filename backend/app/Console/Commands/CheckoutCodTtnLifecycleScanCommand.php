<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pricing\CheckoutCodTtnLifecycleService;
use Illuminate\Console\Command;

final class CheckoutCodTtnLifecycleScanCommand extends Command
{
    protected $signature = 'cod:ttn-lifecycle {--limit=50 : Number of cases per scan}';
    protected $description = 'Project stored Nova Poshta polling observations into COD lifecycle alerts';

    public function handle(CheckoutCodTtnLifecycleService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Batch size must be between 1 and 100');
            return self::FAILURE;
        }
        $this->line(json_encode($service->scanDue($limit), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
