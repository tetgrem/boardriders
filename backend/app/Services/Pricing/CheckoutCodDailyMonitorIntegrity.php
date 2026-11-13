<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only verifier usable by console monitoring without impersonating a user. */
final class CheckoutCodDailyMonitorIntegrity
{
    public function __construct(
        private readonly CheckoutCodDailyReportSigner $reportSigner,
        private readonly CheckoutCodDailyCorrectionSigner $correctionSigner
    ) {
    }

    public function assertReport(object $report): void
    {
        $payload = $this->json((string) $report->payload_json);
        $reconciliation = $this->json((string) $report->reconciliation_json);
        $expected = $this->reportSigner->snapshot(
            (string) $report->report_date, (string) $report->operation_key,
            (int) $report->sealed_by, $payload, $reconciliation
        );
        if ((int) $report->schema_version !== 1
            || !hash_equals((string) $report->payload_hmac, $expected)) {
            throw new DomainException('COD archived daily report signature invalid');
        }

        $sealed = 0;
        $events = DB::table('checkout_cod_daily_report_events')
            ->where('report_id', $report->id)->orderBy('id')->cursor();
        foreach ($events as $event) {
            $expectedEvent = $this->reportSigner->event(
                (string) $event->event_key, (string) $event->event_kind,
                (string) $report->report_key, (int) $event->actor_id,
                (string) $event->report_hmac
            );
            if (!hash_equals((string) $event->report_hmac, (string) $report->payload_hmac)
                || !hash_equals((string) $event->event_hmac, $expectedEvent)
                || !in_array((string) $event->event_kind, ['sealed', 'export_requested'], true)) {
                throw new DomainException('COD daily report event integrity invalid');
            }
            if ($event->event_kind === 'sealed') {
                $sealed++;
                if ((string) $event->event_key !== (string) $report->operation_key
                    || (int) $event->actor_id !== (int) $report->sealed_by) {
                    throw new DomainException('COD daily report seal identity invalid');
                }
            }
        }
        if ($sealed !== 1) {
            throw new DomainException('COD daily report seal audit missing or duplicated');
        }
    }

    /** Returns the verified terminal state of a correction's append-only event chain. */
    public function correctionStatus(object $correction, object $report): string
    {
        $observation = $this->json((string) $correction->observation_json);
        $obsHmac = $this->correctionSigner->observation(
            (string) $report->report_key, (string) $report->payload_hmac, $observation
        );
        $appendixHmac = $this->correctionSigner->appendix(
            (string) $correction->correction_key, (string) $correction->operation_key,
            (string) $report->report_key, (string) $report->payload_hmac,
            (int) $correction->proposed_by, (string) $correction->reason_code,
            (string) $correction->observation_hmac, $observation
        );
        if (!hash_equals((string) $correction->base_report_hmac, (string) $report->payload_hmac)
            || !hash_equals((string) $correction->observation_hmac, $obsHmac)
            || !hash_equals((string) $correction->appendix_hmac, $appendixHmac)) {
            throw new DomainException('COD correction appendix integrity invalid');
        }

        $prevHmac = null;
        $lastKind = null;
        $reviewer = null;
        $count = 0;
        $events = DB::table('checkout_cod_daily_correction_events')
            ->where('correction_id', $correction->id)->orderBy('id')->cursor();

        foreach ($events as $event) {
            $count++;
            $actor = (int) $event->actor_id;
            $kind = (string) $event->event_kind;
            $reason = match ($kind) {
                'proposed' => (string) $correction->reason_code,
                'approved' => 'evidence_reviewed',
                'rejected' => 'insufficient_evidence',
                'published' => 'approved_appendix',
                'export_requested' => 'csv_appendix',
                default => null,
            };
            $allowed = match ($lastKind) {
                null => $kind === 'proposed'
                    && (string) $event->event_key === (string) $correction->operation_key
                    && $actor === (int) $correction->proposed_by
                    && $actor !== (int) $report->sealed_by,
                'proposed' => in_array($kind, ['approved', 'rejected'], true)
                    && !in_array($actor, [(int) $correction->proposed_by, (int) $report->sealed_by], true),
                'approved' => $kind === 'published'
                    && !in_array($actor, [
                        (int) $correction->proposed_by, (int) $report->sealed_by, $reviewer,
                    ], true),
                'published', 'export_requested' => $kind === 'export_requested',
                default => false,
            };
            $expectedEvent = $this->correctionSigner->event(
                (string) $correction->correction_key, (string) $event->event_key,
                $kind, $actor, (string) $event->reason_code,
                $prevHmac, (string) $correction->appendix_hmac
            );
            if (!$allowed || $reason !== (string) $event->reason_code
                || $event->previous_hmac !== $prevHmac
                || (string) $event->appendix_hmac !== (string) $correction->appendix_hmac
                || !hash_equals((string) $event->event_hmac, $expectedEvent)) {
                throw new DomainException('COD correction approval chain invalid');
            }
            if ($kind === 'approved') {
                $reviewer = $actor;
            }
            $lastKind = $kind;
            $prevHmac = (string) $event->event_hmac;
        }

        if ($count === 0) {
            throw new DomainException('COD correction proposal event missing');
        }
        return $lastKind === 'export_requested' ? 'published' : (string) $lastKind;
    }

    private function json(string $value): array
    {
        $result = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new DomainException('COD signed monitoring source JSON invalid');
        }
        return $result;
    }
}
