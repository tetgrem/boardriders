<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Fixed-size polling batch; cases older than 90 days need human reactivation. */
final class CheckoutCodTtnTrackingDueService
{
    /** @return list<string> */
    public function due(int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException(
                'Tracking batch limit must be 1..100'
            );
        }

        $cutoff = now()->subMinutes(15);

        return DB::table('checkout_cod_ttn_tracking_cases as c')
            ->where('c.created_at', '>=', now()->subDays(90))
            ->whereNotExists(static function ($q) use ($cutoff): void {
                $q->selectRaw('1')
                    ->from('checkout_cod_ttn_tracking_observations as o')
                    ->whereColumn(
                        'o.tracking_case_id',
                        'c.id'
                    )
                    ->where('o.observed_started_at', '>', $cutoff);
            })
            ->orderBy('c.id')
            ->limit($limit)
            ->pluck('c.tracking_key')
            ->all();
    }
}
