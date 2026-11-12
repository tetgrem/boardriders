<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodJournalDraftPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodJournalDraftPolicyTest extends TestCase
{
    private CheckoutCodJournalDraftPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CheckoutCodJournalDraftPolicy();
    }

    public function test_journal_draft34_three_lines_balance_customer_carrier_bank(): void
    {
        $rows = $this->policy->entries(2000, 1320, 680);

        $this->assertCount(3, $rows);
        $this->assertSame('carrier_collection', $rows[0]['entry_kind']);
        $this->assertSame('bank_remittance', $rows[1]['entry_kind']);
        $this->assertSame('carrier_fee', $rows[2]['entry_kind']);
        $this->assertSame(2000, $rows[0]['amount_minor']);
        $this->assertSame(680, $rows[1]['amount_minor']);
        $this->assertSame(1320, $rows[2]['amount_minor']);
    }

    public function test_journal_draft34_zero_fee_skips_fee_entry(): void
    {
        $rows = $this->policy->entries(680, 0, 680);

        $this->assertCount(2, $rows);
        $this->assertSame('bank_remittance', $rows[1]['entry_kind']);
    }

    public function test_journal_draft34_carrier_clearing_nets_to_zero(): void
    {
        $summary = $this->policy->summarize(
            $this->policy->entries(2000, 1320, 680)
        );

        $this->assertSame(
            0,
            $summary['carrier_clearing_balance_minor']
        );
        $this->assertSame(4000, $summary['total_debits_minor']);
        $this->assertSame(4000, $summary['total_credits_minor']);
    }

    public function test_journal_draft34_two_orders_balance(): void
    {
        $all = array_merge(
            $this->policy->entries(2000, 1320, 680),
            $this->policy->entries(2000, 1320, 680)
        );

        $summary = $this->policy->summarize($all);

        $this->assertSame(6, $summary['entries_count']);
        $this->assertSame(8000, $summary['total_debits_minor']);
        $this->assertSame(
            0,
            $summary['carrier_clearing_balance_minor']
        );
    }

    public function test_journal_draft34_amounts_remain_integer_at_limit(): void
    {
        $summary = $this->policy->summarize(
            $this->policy->entries(100000000, 1, 99999999)
        );

        $this->assertSame(
            200000000,
            $summary['total_debits_minor']
        );
        $this->assertSame(
            0,
            $summary['carrier_clearing_balance_minor']
        );
    }

    public function test_journal_draft34_mismatched_fee_net_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->entries(2000, 1320, 679);
    }

    public function test_journal_draft34_zero_bank_receipt_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->entries(2000, 2000, 0);
    }

    public function test_journal_draft34_negative_fee_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->entries(2000, -1, 2001);
    }

    public function test_journal_draft34_same_debit_credit_account_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->summarize([
            [
                'entry_kind' => 'carrier_collection',
                'debit_account' => 'draft_carrier_clearing',
                'credit_account' => 'draft_carrier_clearing',
                'amount_minor' => 500,
            ],
        ]);
    }
}
