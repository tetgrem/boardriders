<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pricing\CheckoutCodCaseAlertService;
use Illuminate\Console\Command;

final class CheckoutCodCaseAlertScanCommand extends Command
{
    protected $signature =
        'cod:case-alerts ' .
        '{--limit=100 : Cases per scan} ' .
        '{--enqueue-limit=100 : Outbox jobs per scan}';

    protected $description =
        'Scan COD case SLAs and enqueue deduplicated CRM alerts';

    public function handle(
        CheckoutCodCaseAlertService $alerts
    ): int {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT
        );

        $enqueue = filter_var(
            $this->option('enqueue-limit'),
            FILTER_VALIDATE_INT
        );

        if (
            $limit === false
            || $enqueue === false
            || $limit < 1
            || $limit > 500
            || $enqueue < 1
            || $enqueue > 500
        ) {
            $this->error(
                'Limits must be integers from 1 to 500'
            );

            return self::FAILURE;
        }

        $scan = $alerts->scan($limit);
        $jobs = $alerts->enqueue($enqueue);

        $this->line(json_encode([
            'scan' => $scan,
            'queue' => $jobs,
        ], JSON_THROW_ON_ERROR));

        return $jobs['failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
