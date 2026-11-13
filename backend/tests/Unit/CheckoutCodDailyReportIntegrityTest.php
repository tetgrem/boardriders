<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodDailyReportReconciliation;
use App\Services\Pricing\CheckoutCodDailyReportSigner;
use Tests\TestCase;

final class CheckoutCodDailyReportIntegrityTest extends TestCase
{
    private function sample(): array
    {
        return [
            'financial' => [
                'postings_count' => 1,
                'posted_bank_minor' => 680,
                'journal_gross_minor' => 2000,
                'journal_fee_minor' => 1320,
                'posting_journal_mismatch_count' => 0,
                'register_amount_mismatch_count' => 0,
                'claimed_register_gross_minor' => 0,
                'claimed_register_fee_minor' => 0,
                'claimed_register_net_minor' => 0,
                'missing_register_count' => 1,
            ],
            'cases' => [
                'created_count' => 1,
                'open_count' => 1,
                'in_progress_count' => 0,
                'waiting_external_count' => 0,
                'closed_internal_review_count' => 0,
            ],
            'notifications' => [
                'outbox_created_count' => 1,
                'outbox_delivered_count' => 0,
                'outbox_suppressed_count' => 0,
                'outbox_pending_count' => 1,
            ],
            'daily' => [[
                'date' => '2026-11-12',
                'postings_count' => 1,
                'posted_bank_minor' => 680,
                'cases_created_count' => 1,
                'alerts_created_count' => 1,
            ]],
        ];
    }

    public function test_cod_daily45_missing_register_is_not_financial_approval(): void
    {
        $r = (new CheckoutCodDailyReportReconciliation())->assess(
            $this->sample()
        );

        $this->assertSame('awaiting_register_claims', $r['status']);
        $this->assertFalse($r['customer_payment_verified']);
        $this->assertSame([], $r['issues']);
    }

    public function test_cod_daily45_balanced_register_still_unverified(): void
    {
        $s = $this->sample();
        $s['financial']['missing_register_count'] = 0;
        $s['financial']['claimed_register_gross_minor'] = 2000;
        $s['financial']['claimed_register_fee_minor'] = 1320;
        $s['financial']['claimed_register_net_minor'] = 680;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertSame('internally_consistent_unverified', $r['status']);
        $this->assertFalse($r['customer_payment_verified']);
    }

    public function test_cod_daily45_empty_day_has_own_status(): void
    {
        $s = $this->sample();
        $s['financial']['postings_count'] = 0;
        $s['financial']['missing_register_count'] = 0;
        $s['financial']['posted_bank_minor'] = 0;
        $s['financial']['journal_gross_minor'] = 0;
        $s['financial']['journal_fee_minor'] = 0;
        $s['cases']['created_count'] = 0;
        $s['cases']['open_count'] = 0;
        $s['notifications']['outbox_created_count'] = 0;
        $s['notifications']['outbox_pending_count'] = 0;
        $s['daily'][0]['postings_count'] = 0;
        $s['daily'][0]['posted_bank_minor'] = 0;
        $s['daily'][0]['cases_created_count'] = 0;
        $s['daily'][0]['alerts_created_count'] = 0;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertSame('no_bank_postings', $r['status']);
        $this->assertSame([], $r['issues']);
    }

    public function test_cod_daily45_detects_journal_bank_total_mismatch(): void
    {
        $s = $this->sample();
        $s['financial']['journal_fee_minor'] = 1319;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertContains('journal_bank_total_mismatch', $r['issues']);
        $this->assertSame('investigation_required', $r['status']);
    }

    public function test_cod_daily45_detects_per_posting_mismatch(): void
    {
        $s = $this->sample();
        $s['financial']['posting_journal_mismatch_count'] = 1;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertContains('posting_journal_amount_mismatch', $r['issues']);
    }

    public function test_cod_daily45_detects_claim_arithmetic_mismatch(): void
    {
        $s = $this->sample();
        $s['financial']['claimed_register_gross_minor'] = 2000;
        $s['financial']['claimed_register_fee_minor'] = 1320;
        $s['financial']['claimed_register_net_minor'] = 681;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertContains('register_claim_arithmetic_mismatch', $r['issues']);
    }

    public function test_cod_daily45_detects_case_status_mismatch(): void
    {
        $s = $this->sample();
        $s['cases']['open_count'] = 0;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertContains('case_status_count_mismatch', $r['issues']);
    }

    public function test_cod_daily45_detects_alert_state_mismatch(): void
    {
        $s = $this->sample();
        $s['notifications']['outbox_pending_count'] = 0;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertContains('alert_delivery_state_mismatch', $r['issues']);
    }

    public function test_cod_daily45_detects_daily_aggregation_mismatch(): void
    {
        $s = $this->sample();
        $s['daily'][0]['posted_bank_minor'] = 679;

        $r = (new CheckoutCodDailyReportReconciliation())->assess($s);

        $this->assertContains('daily_aggregate_mismatch', $r['issues']);
    }

    public function test_cod_daily45_signer_canonicalizes_jsonb_object_key_order(): void
    {
        $s = new CheckoutCodDailyReportSigner();

        $a = [
            'staff' => ['b' => 1, 'a' => 2],
            'days' => [['z' => 9, 'x' => 8]],
        ];

        $b = [
            'days' => [['x' => 8, 'z' => 9]],
            'staff' => ['a' => 2, 'b' => 1],
        ];

        $this->assertSame(
            $s->canonicalJson($a),
            $s->canonicalJson($b)
        );

        $this->assertSame(
            $s->snapshot(
                '2026-11-12',
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                1200,
                $a,
                []
            ),
            $s->snapshot(
                '2026-11-12',
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                1200,
                $b,
                []
            )
        );
    }

    public function test_cod_daily45_signer_detects_changed_money_or_actor(): void
    {
        $s = new CheckoutCodDailyReportSigner();
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $a = $s->snapshot('2026-11-12', $id, 1200, ['money' => 680], []);
        $b = $s->snapshot('2026-11-12', $id, 1200, ['money' => 681], []);
        $c = $s->snapshot('2026-11-12', $id, 1201, ['money' => 680], []);

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_cod_daily45_event_signature_is_bound_to_kind_and_report(): void
    {
        $s = new CheckoutCodDailyReportSigner();
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $key = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $a = $s->event($id, 'sealed', $key, 1200, str_repeat('a', 64));
        $b = $s->event($id, 'export_requested', $key, 1200, str_repeat('a', 64));
        $c = $s->event($id, 'sealed', $id, 1200, str_repeat('a', 64));

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}
