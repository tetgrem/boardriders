<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Jobs\DeliverCheckoutCodCaseAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Durable SLA scanner + retryable dispatch.
 * No external payment or customer data.
 */
final class CheckoutCodCaseAlertService
{
    public function __construct(
        private readonly CheckoutCodCaseAlertPolicy $policy
    ) {
    }

    /**
     * @return array{
     *   scanned:int,
     *   created:int,
     *   next_before_id:?int
     * }
     */
    public function scan(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException(
                'Invalid COD alert scan limit'
            );
        }

        return DB::transaction(function () use ($limit): array {
            $cursor = DB::table(
                'checkout_cod_case_alert_scan_cursor'
            )
                ->where('cursor_name', 'sla_v43')
                ->lockForUpdate()
                ->first();

            if ($cursor === null) {
                throw new \RuntimeException(
                    'COD alert scan cursor is missing'
                );
            }

            $q = DB::table('checkout_cod_operations_cases')
                ->orderByDesc('id')
                ->limit($limit);

            if ($cursor->last_case_id !== null) {
                $q->where('id', '<', $cursor->last_case_id);
            }

            $cases = $q->get();
            $now = CarbonImmutable::now('UTC');
            $created = 0;

            foreach ($cases as $case) {
                // Lock against concurrent manual status changes.
                $locked = DB::table(
                    'checkout_cod_operations_cases'
                )
                    ->where('id', $case->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    continue;
                }

                $alerts = $this->policy->alerts(
                    (string) $locked->case_status,
                    $locked->assigned_to === null
                        ? null
                        : (int) $locked->assigned_to,
                    $locked->created_at,
                    $locked->sla_due_at,
                    $now
                );

                foreach ($alerts as $alert) {
                    $created += DB::table(
                        'checkout_cod_case_alert_outbox'
                    )->insertOrIgnore([
                        'case_id' => (int) $locked->id,
                        'case_revision' => (int) $locked->revision,
                        'alert_kind' => $alert['kind'],
                        'recipient_id' => $alert['recipient_id'],
                        'next_dispatch_at' => $now,
                        'created_at' => $now,
                    ]);
                }
            }

            $count = $cases->count();

            $next = $count === $limit
                ? (int) $cases->last()->id
                : null;

            DB::table('checkout_cod_case_alert_scan_cursor')
                ->where('cursor_name', 'sla_v43')
                ->update([
                    'last_case_id' => $next,
                    'updated_at' => $now,
                ]);

            return [
                'scanned' => $count,
                'created' => $created,
                'next_before_id' => $next,
            ];
        }, 3);
    }

    /**
     * Claims pending outbox items for five minutes, then
     * dispatches after the database transaction commits.
     *
     * @return array{enqueued:int,failed:int}
     */
    public function enqueue(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException(
                'Invalid COD alert enqueue limit'
            );
        }

        $ids = DB::transaction(
            static function () use ($limit): array {
                $now = CarbonImmutable::now('UTC');

                $rows = DB::table(
                    'checkout_cod_case_alert_outbox'
                )
                    ->whereNull('delivered_at')
                    ->whereNull('suppressed_at')
                    ->where('next_dispatch_at', '<=', $now)
                    ->orderBy('id')
                    ->limit($limit)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->get(['id']);

                $ids = $rows->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                if ($ids !== []) {
                    DB::table(
                        'checkout_cod_case_alert_outbox'
                    )
                        ->whereIn('id', $ids)
                        ->update([
                            'next_dispatch_at' => $now
                                ->addMinutes(5),
                        ]);
                }

                return $ids;
            },
            3
        );

        $enqueued = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                DeliverCheckoutCodCaseAlert::dispatch($id)
                    ->onQueue('cod-alerts');

                $enqueued++;
            } catch (Throwable $e) {
                $failed++;
                report($e);

                DB::table('checkout_cod_case_alert_outbox')
                    ->where('id', $id)
                    ->whereNull('delivered_at')
                    ->whereNull('suppressed_at')
                    ->update([
                        'next_dispatch_at' =>
                            CarbonImmutable::now('UTC'),
                    ]);
            }
        }

        return [
            'enqueued' => $enqueued,
            'failed' => $failed,
        ];
    }
}
