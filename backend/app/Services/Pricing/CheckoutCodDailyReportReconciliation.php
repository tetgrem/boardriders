<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/** Internal aggregates reconciliation; not external proof of COD customer payment. */
final class CheckoutCodDailyReportReconciliation
{
    /** @return array{status:string,issues:list<string>,customer_payment_verified:bool} */
    public function assess(array $report): array
    {
        $f = $report['financial'];
        $c = $report['cases'];
        $n = $report['notifications'];
        $daily = $report['daily'][0] ?? [];
        $issues = [];

        if ((int) $f['journal_gross_minor'] - (int) $f['journal_fee_minor']
            !== (int) $f['posted_bank_minor']) {
            $issues[] = 'journal_bank_total_mismatch';
        }

        if ((int) $f['posting_journal_mismatch_count'] > 0) {
            $issues[] = 'posting_journal_amount_mismatch';
        }

        if ((int) $f['register_amount_mismatch_count'] > 0) {
            $issues[] = 'register_amount_mismatch';
        }

        if ((int) $f['claimed_register_gross_minor']
            - (int) $f['claimed_register_fee_minor']
            !== (int) $f['claimed_register_net_minor']) {
            $issues[] = 'register_claim_arithmetic_mismatch';
        }

        $caseStatuses = (int) $c['open_count']
            + (int) $c['in_progress_count']
            + (int) $c['waiting_external_count']
            + (int) $c['closed_internal_review_count'];

        if ($caseStatuses !== (int) $c['created_count']) {
            $issues[] = 'case_status_count_mismatch';
        }

        $alertStates = (int) $n['outbox_delivered_count']
            + (int) $n['outbox_suppressed_count']
            + (int) $n['outbox_pending_count'];

        if ($alertStates !== (int) $n['outbox_created_count']) {
            $issues[] = 'alert_delivery_state_mismatch';
        }

        if (count($report['daily']) !== 1
            || (int) ($daily['postings_count'] ?? -1) !== (int) $f['postings_count']
            || (int) ($daily['posted_bank_minor'] ?? -1) !== (int) $f['posted_bank_minor']
            || (int) ($daily['cases_created_count'] ?? -1) !== (int) $c['created_count']
            || (int) ($daily['alerts_created_count'] ?? -1) !== (int) $n['outbox_created_count']) {
            $issues[] = 'daily_aggregate_mismatch';
        }

        if ($issues !== []) {
            $status = 'investigation_required';
        } elseif ((int) $f['postings_count'] === 0) {
            $status = 'no_bank_postings';
        } elseif ((int) $f['missing_register_count'] > 0) {
            $status = 'awaiting_register_claims';
        } else {
            $status = 'internally_consistent_unverified';
        }

        return [
            'status' => $status,
            'issues' => $issues,
            'customer_payment_verified' => false,
        ];
    }
}
