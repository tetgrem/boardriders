<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pricing\CheckoutCodCorrectionSlaScanService;
use Illuminate\Console\Command;

final class CheckoutCodCorrectionSlaScanCommand extends Command
{
    protected $signature = 'cod:correction-sla {--limit=50 : Corrections to scan per run}';
    protected $description = 'Monitor correction approval/publication SLA and create CRM escalations';

    public function handle(CheckoutCodCorrectionSlaScanService $scanner): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 200) {
            $this->error('COD correction SLA limit must be between 1 and 200');
            return self::FAILURE;
        }
        $this->line(json_encode($scanner->scan($limit), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
