<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A stable, finite financial-evidence projection for one sealed UTC day. */
final class CheckoutCodDailyCorrectionObservation
{
    private const MONEY_KEYS = [
        'postings_count', 'posted_bank_minor', 'journal_gross_minor',
        'journal_fee_minor', 'register_claims_count', 'missing_register_count',
        'claimed_register_gross_minor', 'claimed_register_fee_minor',
        'claimed_register_net_minor', 'posting_journal_mismatch_count',
        'register_amount_mismatch_count',
    ];

    public function __construct(private readonly CheckoutCodAnalyticsService $analytics)
    {
    }

    /** @return array{baseline:array,current:array,delta:array,late_signals:array,has_changes:bool} */
    public function observe(object $report, bool $system = false): array
    {
        $day = (string) $report->report_date;
        $start = CarbonImmutable::parse($day, 'UTC')->startOfDay();
        $end = $start->addDay();
        $original = json_decode((string) $report->payload_json, true, 512, JSON_THROW_ON_ERROR);
        $live = $system
            ? $this->analytics->systemSnapshot($day, $day)
            : $this->analytics->report($day, $day);

        $baseline = $this->select($original['financial']);
        $current = $this->select($live['financial']);
        $delta = [];

        foreach (self::MONEY_KEYS as $key) {
            if ($baseline[$key] !== $current[$key]) {
                $delta[$key] = [
                    'before' => $baseline[$key],
                    'after' => $current[$key],
                    'difference' => $current[$key] - $baseline[$key],
                ];
            }
        }

        $lateBooked = DB::table('checkout_cod_bank_receipt_postings')
            ->where('booked_on', $day)
            ->where('created_at', '>', $report->sealed_at)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS amount')
            ->first();

        // Captures tied to a posting in this report's created-at cohort,
        // or to a posting booked on this date, even if posted later.
        $postingScope = static function ($query) use ($day, $start, $end): void {
            $query->selectRaw('1')
                ->from('checkout_cod_bank_receipt_postings as p')
                ->where(function ($q) use ($day, $start, $end): void {
                    $q->where('p.booked_on', $day)
                        ->orWhere(function ($inner) use ($start, $end): void {
                            $inner->where('p.created_at', '>=', $start)
                                ->where('p.created_at', '<', $end);
                        });
                });
        };

        $lateRegisters = DB::table('checkout_cod_novapay_register_captures as r')
            ->where('r.created_at', '>', $report->sealed_at)
            ->whereExists(function ($q) use ($postingScope): void {
                $postingScope($q);
                $q->whereColumn('p.id', 'r.bank_posting_id');
            })->count();

        $lateBankReversals = DB::table('checkout_cod_bank_evidence_events as e')
            ->where('e.received_at', '>', $report->sealed_at)
            ->where('e.event_kind', 'reversal')
            ->whereExists(function ($q) use ($postingScope): void {
                $postingScope($q);
                $q->whereColumn('p.reference_hmac', 'e.reference_hmac');
            })->count();

        $lateGatewayReversals = DB::table('checkout_cod_gateway_reports as e')
            ->where('e.received_at', '>', $report->sealed_at)
            ->where('e.event_kind', 'reversal_reported')
            ->whereExists(function ($q) use ($postingScope): void {
                $postingScope($q);
                $q->whereColumn('p.reference_hmac', 'e.reference_hmac');
            })->count();

        $signals = [
            'late_booking_postings_count' => (int) $lateBooked->n,
            'late_booking_bank_minor' => (int) $lateBooked->amount,
            'late_register_claims_count' => (int) $lateRegisters,
            'late_signed_bank_reversals_count' => (int) $lateBankReversals,
            'late_gateway_reversal_reports_count' => (int) $lateGatewayReversals,
        ];

        return [
            'baseline' => $baseline,
            'current' => $current,
            'delta' => $delta,
            'late_signals' => $signals,
            'has_changes' => $delta !== [] || array_sum($signals) > 0,
        ];
    }

    private function select(array $financial): array
    {
        $out = [];
        foreach (self::MONEY_KEYS as $key) {
            $out[$key] = (int) $financial[$key];
        }
        return $out;
    }
}
