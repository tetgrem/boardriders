<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** CLI scanner of the signed, append-only correction approval workflow. */
final class CheckoutCodCorrectionSlaScanService
{
    public function __construct(
        private readonly CheckoutCodDailyMonitorIntegrity $integrity,
        private readonly CheckoutCodCorrectionSlaSigner $signer
    ) {
    }

    /** @return array<string,int|null|bool> */
    public function scan(int $limit = 50): array
    {
        if (!app()->runningInConsole()) {
            throw new AuthorizationException('COD correction SLA scan is CLI-only');
        }
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('COD correction SLA scan limit must be 1 to 200');
        }
        $asOf = CarbonImmutable::now('UTC');
        $policy = CheckoutCodCorrectionSlaPolicy::fromConfig();

        return DB::transaction(function () use ($limit, $asOf, $policy): array {
            $cursor = DB::table('checkout_cod_correction_sla_cursor')
                ->where('cursor_name', 'correction_sla_v48')
                ->lockForUpdate()->first();
            if ($cursor === null) {
                throw new DomainException('COD correction SLA cursor missing');
            }

            $query = DB::table('checkout_cod_daily_corrections')->orderByDesc('id')->limit($limit);
            if ($cursor->last_correction_id !== null) {
                $query->where('id', '<', $cursor->last_correction_id);
            }
            $corrections = $query->get();
            $created = 0;
            $active = 0;
            $managerAlerts = 0;

            foreach ($corrections as $correction) {
                $report = DB::table('checkout_cod_daily_reports')
                    ->where('id', $correction->report_id)->first();
                if ($report === null) {
                    throw new DomainException('COD correction base report missing');
                }
                $this->integrity->assertReport($report);
                $status = $this->integrity->correctionStatus($correction, $report);
                if ($status === 'published') {
                    continue;
                }
                $phase = match ($status) {
                    'proposed' => 'review',
                    'approved' => 'publication',
                    'rejected' => 'rejection',
                    default => throw new DomainException('Unsupported COD correction SLA state'),
                };
                $event = DB::table('checkout_cod_daily_correction_events')
                    ->where('correction_id', $correction->id)
                    ->where('event_kind', $status)
                    ->orderByDesc('id')->first(['id', 'created_at']);
                if ($event === null) {
                    throw new DomainException('COD correction SLA phase event missing');
                }
                $decision = $policy->evaluate(
                    $phase, CarbonImmutable::parse($event->created_at), $asOf
                );
                if ($decision === null) {
                    continue;
                }
                $active++;
                if ($decision['target_role'] === 'finance_management') {
                    $managerAlerts++;
                }
                $created += DB::table('checkout_cod_correction_sla_alerts')->insertOrIgnore([
                    'correction_id' => (int) $correction->id,
                    'phase_event_id' => (int) $event->id,
                    'phase' => $phase,
                    'stage' => $decision['stage'],
                    'severity' => $decision['severity'],
                    'target_role' => $decision['target_role'],
                    'due_at' => $decision['due_at'],
                    'alert_hmac' => $this->signer->alert(
                        (string) $correction->correction_key,
                        (string) $correction->appendix_hmac,
                        (int) $event->id,
                        $phase,
                        $decision['stage'],
                        $decision['severity'],
                        $decision['target_role'],
                        $this->signer->stamp($decision['due_at'])
                    ),
                    'created_at' => $asOf,
                ]);
            }

            $next = $corrections->count() === $limit
                ? (int) $corrections->last()->id : null;
            DB::table('checkout_cod_correction_sla_cursor')
                ->where('cursor_name', 'correction_sla_v48')
                ->update(['last_correction_id' => $next, 'updated_at' => $asOf]);

            return [
                'scanned' => $corrections->count(),
                'new_alerts' => $created,
                'due_or_overdue_at_scan' => $active,
                'management_targets_at_scan' => $managerAlerts,
                'next_before_id' => $next,
                'orders_payment_status_changed' => false,
            ];
        }, 3);
    }
}
