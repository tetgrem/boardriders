<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Fiscal62\Fiscal62StatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class Fiscal62PollJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $actorId,
    ) {}

    public function handle(
        Fiscal62StatusService $service
    ): void {
        $service->poll(
            $this->ticketId,
            $this->actorId
        );
    }

    public function backoff(): array
    {
        return [15, 60, 300];
    }
}
