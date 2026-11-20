<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Fiscal61\Fiscal61SimulationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class Fiscal61PreviewJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public readonly int $documentId,
        public readonly int $requestedBy,
    ) {
    }

    public function handle(
        Fiscal61SimulationService $service
    ): void {
        $service->process(
            $this->documentId,
            $this->requestedBy
        );
    }

    public function backoff(): array
    {
        return [10, 60, 180];
    }
}
