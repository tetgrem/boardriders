<?php

namespace App\Console\Commands;

use App\Services\Pricing\CheckoutCodTtnTrackingDueService;
use App\Services\Pricing\CheckoutCodTtnTrackingPollService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/** Run from trusted scheduler. Observations cannot settle COD or mark delivery. */
final class PollCodTtnTracking extends Command
{
    protected $signature = 'cod:tracking-poll {--limit=25}';

    protected $description =
        'Poll read-only Nova Poshta status for reviewed historical TTN candidates';

    public function handle(
        CheckoutCodTtnTrackingDueService $due,
        CheckoutCodTtnTrackingPollService $poll
    ): int {
        $rawLimit = $this->option('limit');

        if (
            !is_numeric($rawLimit)
            || (string) (int) $rawLimit !== (string) $rawLimit
            || (int) $rawLimit < 1
            || (int) $rawLimit > 100
        ) {
            $this->error('Invalid tracking poll limit (1..100)');
            return self::FAILURE;
        }

        $processed = 0;
        $failures = 0;

        foreach ($due->due((int) $rawLimit) as $trackingKey) {
            try {
                $result = $poll->pollScheduled(
                    $trackingKey,
                    (string) Str::uuid()
                );

                $processed++;

                if ($result['outcome'] !== 'observed') {
                    $failures++;
                }
            } catch (Throwable) {
                // Do not expose raw exceptions, API keys or personal data.
                $failures++;
            }
        }

        $this->info(
            "Tracking poll finished: {$processed} recorded, "
            . "{$failures} unavailable/inconclusive"
        );

        return self::SUCCESS;
    }
}
