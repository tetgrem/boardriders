<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodPrivatRefetchPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodPrivatRefetchPolicyTest extends TestCase
{
    private const KEY = 'test-secret-for-refetch-unit-36';
    private const ALIAS = 'pb_primary_ua';
    private const REF = 'BANKREF_36_TEST';

    private function row(string $ref = self::REF, string $refn = '1',
                         string $direction = 'credit', int $amount = 680, string $day = '2026-11-11'): array
    {
        return [
            'ref' => $ref,
            'refn' => $refn,
            'direction' => $direction,
            'amount_minor' => $amount,
            'booked_on' => $day,
        ];
    }

    private function digest(array $parts): string
    {
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), self::KEY);
    }

    private function compareRows(array $rows): array
    {
        return (new CheckoutCodPrivatRefetchPolicy())->compare(
            $rows,
            self::ALIAS,
            $this->digest(['cod-bank-reference-v1', self::REF]),
            $this->digest(['cod-privatbank-tx-v1', self::ALIAS, self::REF, '1']),
            $this->digest(['cod-privatbank-row-v1', self::ALIAS,
                self::REF, '1', 'credit', 680, '2026-11-11']),
            680,
            '2026-11-11',
            self::KEY
        );
    }

    public function test_bank_refetch36_exact_remote_row_remains_unverified(): void
    {
        $r = $this->compareRows([$this->row()]);
        $this->assertSame('consistent_unverified', $r['result_code']);
        $this->assertSame(1, $r['exact_rows']);
        $this->assertFalse($r['bank_native_signature_verified']);
        $this->assertFalse($r['funds_received_verified']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_bank_refetch36_missing_reference_detected(): void
    {
        $this->assertSame('reference_not_found',
            $this->compareRows([$this->row(ref: 'ANOTHER_REF36')])['result_code']);
    }

    public function test_bank_refetch36_changed_transaction_id_detected(): void
    {
        $r = $this->compareRows([$this->row(refn: '2')]);
        $this->assertSame('transaction_changed', $r['result_code']);
        $this->assertSame(0, $r['identity_rows']);
    }

    public function test_bank_refetch36_changed_amount_detected(): void
    {
        $this->assertSame('transaction_changed',
            $this->compareRows([$this->row(amount: 700)])['result_code']);
    }

    public function test_bank_refetch36_debit_is_not_original_credit(): void
    {
        $this->assertSame('transaction_changed',
            $this->compareRows([$this->row(direction: 'debit')])['result_code']);
    }

    public function test_bank_refetch36_same_reference_multiple_transactions_detected(): void
    {
        $r = $this->compareRows([$this->row(), $this->row(refn: '2')]);
        $this->assertSame('ambiguous_reference', $r['result_code']);
        $this->assertSame(2, $r['reference_rows']);
    }

    public function test_bank_refetch36_changed_booking_date_detected(): void
    {
        $this->assertSame('transaction_changed',
            $this->compareRows([$this->row(day: '2026-11-12')])['result_code']);
    }

    public function test_bank_refetch36_rejects_invalid_normalized_response(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compareRows([['ref' => self::REF, 'refn' => '1']]);
    }
}
