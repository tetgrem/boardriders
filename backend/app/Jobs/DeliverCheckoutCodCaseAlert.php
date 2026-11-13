<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Pricing\CheckoutCodCaseAlertPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Delivers an outbox entry to CRM inbox.
 * At-least-once execution with idempotent persistence.
 */
final class DeliverCheckoutCodCaseAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 30;

    public function __construct(
        public readonly int $alertId
    ) {
    }

    public function handle(
        CheckoutCodCaseAlertPolicy $policy
    ): void {
        $probe = DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('id', $this->alertId)
            ->first(['case_id']);

        if ($probe === null) {
            return;
        }

        DB::transaction(function () use (
            $policy,
            $probe
        ): void {
            // Consistent locking: case first, then outbox.
            $case = DB::table(
                'checkout_cod_operations_cases'
            )
                ->where('id', $probe->case_id)
                ->lockForUpdate()
                ->first();

            $alert = DB::table(
                'checkout_cod_case_alert_outbox'
            )
                ->where('id', $this->alertId)
                ->lockForUpdate()
                ->first();

            if (
                $alert === null
                || $alert->delivered_at !== null
                || $alert->suppressed_at !== null
            ) {
                return;
            }

            $valid = false;

            if (
                $case !== null
                && (int) $case->revision
                === (int) $alert->case_revision
            ) {
                $candidates = $policy->alerts(
                    (string) $case->case_status,
                    $case->assigned_to === null
                        ? null
                        : (int) $case->assigned_to,
                    $case->created_at,
                    $case->sla_due_at,
                    CarbonImmutable::now('UTC')
                );

                foreach ($candidates as $candidate) {
                    $recipient = $alert->recipient_id === null
                        ? null
                        : (int) $alert->recipient_id;

                    if (
                        $candidate['kind']
                        === (string) $alert->alert_kind
                        && $candidate['recipient_id']
                        === $recipient
                    ) {
                        $valid = true;
                        break;
                    }
                }
            }

            if (!$valid) {
                DB::table(
                    'checkout_cod_case_alert_outbox'
                )
                    ->where('id', $this->alertId)
                    ->update([
                        'suppressed_at' =>
                            CarbonImmutable::now('UTC'),
                    ]);

                return;
            }

            DB::table('checkout_cod_case_alert_inbox')
                ->insertOrIgnore([
                    'outbox_id' => (int) $alert->id,
                    'case_id' => (int) $alert->case_id,
                    'recipient_id' => $alert->recipient_id,
                    'alert_kind' => (string) $alert->alert_kind,
                    'created_at' => CarbonImmutable::now('UTC'),
                ]);

            DB::table('checkout_cod_case_alert_outbox')
                ->where('id', $this->alertId)
                ->update([
                    'delivered_at' => CarbonImmutable::now('UTC'),
                ]);
        }, 3);
    }
}
