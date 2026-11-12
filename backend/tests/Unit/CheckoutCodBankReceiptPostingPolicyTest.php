<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodBankReceiptPostingPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodBankReceiptPostingPolicyTest extends TestCase
{
    public function test_bank_receipt38_exact_two_balanced_lines(): void
    {
        $lines = (new CheckoutCodBankReceiptPostingPolicy())->lines(2000, 1320, 680);
        $this->assertCount(2, $lines);
        $this->assertSame('debit', $lines[0]['side']);
        $this->assertSame('cod_bank_asset', $lines[0]['account_code']);
        $this->assertSame(680, $lines[0]['amount_minor']);
        $this->assertSame('credit', $lines[1]['side']);
        $this->assertSame('cod_carrier_clearing', $lines[1]['account_code']);
        $this->assertSame(680, $lines[1]['amount_minor']);
    }

    public function test_bank_receipt38_two_order_batch_one_bank_credit(): void
    {
        $lines = (new CheckoutCodBankReceiptPostingPolicy())->lines(4000, 2640, 1360);
        $this->assertCount(2, $lines);
        $this->assertSame(1360, $lines[0]['amount_minor']);
    }

    public function test_bank_receipt38_zero_fee_possible(): void
    {
        $lines = (new CheckoutCodBankReceiptPostingPolicy())->lines(680, 0, 680);
        $this->assertSame(680, $lines[1]['amount_minor']);
    }

    public function test_bank_receipt38_missing_bank_credit_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodBankReceiptPostingPolicy())->lines(2000, 2000, 0);
    }

    public function test_bank_receipt38_wrong_fee_balance_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodBankReceiptPostingPolicy())->lines(2000, 1320, 700);
    }

    public function test_bank_receipt38_negative_fee_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodBankReceiptPostingPolicy())->lines(2000, -1, 2001);
    }

    public function test_bank_receipt38_amount_limit_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CheckoutCodBankReceiptPostingPolicy())->lines(100000001, 0, 100000001);
    }
}
