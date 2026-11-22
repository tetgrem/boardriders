<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Fiscal62\Fiscal62TicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class Fiscal62SendJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 35;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $actorId,
    ) {}

    public function handle(
        Fiscal62TicketService $service
    ): void {
        $service->sendOnce(
            $this->ticketId,
            $this->actorId
        );
    }
}
