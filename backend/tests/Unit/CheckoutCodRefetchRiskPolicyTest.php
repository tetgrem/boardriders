<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodRefetchRiskPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodRefetchRiskPolicyTest extends TestCase
{
    private function row(int $id, string $code): array
    {
        return [
            'id' => $id,
            'result_code' => $code,
            'statement_entry_id' => 10,
            'row_hmac' => str_repeat('a', 64),
        ];
    }

    public function test_refetch_risk37_no_refetch_does_not_invent_a_hold(): void
    {
        $r = (new CheckoutCodRefetchRiskPolicy())->assess([]);
        $this->assertSame('no_api_recheck_yet', $r['status']);
        $this->assertFalse($r['blocks_financial_approval']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_refetch_risk37_consistent_observation_is_not_confirmation(): void
    {
        $r = (new CheckoutCodRefetchRiskPolicy())->assess([
            $this->row(1, 'consistent_unverified'),
        ]);
        $this->assertSame('api_rechecks_consistent_unverified', $r['status']);
        $this->assertFalse($r['blocks_financial_approval']);
        $this->assertFalse($r['funds_received_verified']);
        $this->assertFalse($r['payment_posting_allowed']);
    }

    public function test_refetch_risk37_changed_transaction_blocks(): void
    {
        $r = (new CheckoutCodRefetchRiskPolicy())->assess([
            $this->row(1, 'transaction_changed'),
        ]);
        $this->assertSame('bank_refetch_anomaly_unresolved', $r['status']);
        $this->assertTrue($r['blocks_financial_approval']);
    }

    public function test_refetch_risk37_later_consistent_recheck_does_not_erase_alert(): void
    {
        $r = (new CheckoutCodRefetchRiskPolicy())->assess([
            $this->row(1, 'ambiguous_reference'),
            $this->row(2, 'consistent_unverified'),
        ]);
        $this->assertSame(1, $r['negative_observations_count']);
        $this->assertSame(2, $r['latest_refetch_id']);
        $this->assertTrue($r['blocks_financial_approval']);
    }

    public function test_refetch_risk37_missing_reference_blocks(): void
    {
        $r = (new CheckoutCodRefetchRiskPolicy())->assess([
            $this->row(1, 'reference_not_found'),
        ]);
        $this->assertTrue($r['blocks_financial_approval']);
    }

    public function test_refetch_risk37_out_of_order_history_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodRefetchRiskPolicy())->assess([
            $this->row(2, 'consistent_unverified'),
            $this->row(1, 'transaction_changed'),
        ]);
    }

    public function test_refetch_risk37_unknown_result_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodRefetchRiskPolicy())->assess([
            $this->row(1, 'bank_payment_verified'),
        ]);
    }
}
