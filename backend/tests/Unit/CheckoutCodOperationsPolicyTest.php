<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodOperationsPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodOperationsPolicyTest extends TestCase
{
    private function facts(): array
    {
        return [
            'register_present' => true,
            'register_balanced' => true,
            'bank_balanced' => true,
            'orders_balanced' => true,
            'financial_hold' => false,
            'refetch_anomaly' => false,
            'reversal_reported' => false,
            'payment_status_conflict' => false,
            'payment_method_conflict' => false,
            'ttn_candidate_missing' => false,
            'ttn_candidate_ambiguous' => false,
        ];
    }

    public function test_cod_operations41_clean_claim_still_awaits_external_proof(): void
    {
        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($this->facts());

        $this->assertSame(
            'awaiting_external_proof',
            $result['status']
        );

        $this->assertSame('low', $result['priority']);
        $this->assertFalse($result['requires_attention']);
    }

    public function test_cod_operations41_missing_register(): void
    {
        $f = $this->facts();

        $f['register_present'] = false;
        $f['register_balanced'] = false;

        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($f);

        $this->assertSame(
            'awaiting_register',
            $result['status']
        );

        $this->assertSame(
            ['register_missing'],
            $result['flags']
        );
    }

    public function test_cod_operations41_mismatch_is_critical(): void
    {
        $f = $this->facts();
        $f['register_balanced'] = false;

        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($f);

        $this->assertSame(
            'investigation_required',
            $result['status']
        );

        $this->assertContains(
            'register_totals_mismatch',
            $result['flags']
        );
    }

    public function test_cod_operations41_bank_mismatch_is_critical(): void
    {
        $f = $this->facts();
        $f['bank_balanced'] = false;

        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($f);

        $this->assertSame(
            'critical',
            $result['priority']
        );
    }

    public function test_cod_operations41_reversal_has_priority_over_missing_register(): void
    {
        $f = $this->facts();

        $f['register_present'] = false;
        $f['reversal_reported'] = true;

        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($f);

        $this->assertSame(
            'investigation_required',
            $result['status']
        );
    }

    public function test_cod_operations41_ambiguous_ttn_requires_investigation(): void
    {
        $f = $this->facts();
        $f['ttn_candidate_ambiguous'] = true;

        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($f);

        $this->assertSame(
            'ttn_investigation_required',
            $result['status']
        );
    }

    public function test_cod_operations41_missing_ttn_is_not_payment_proof(): void
    {
        $f = $this->facts();
        $f['ttn_candidate_missing'] = true;

        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($f);

        $this->assertSame(
            'awaiting_external_proof',
            $result['status']
        );

        $this->assertTrue($result['requires_attention']);
    }

    public function test_cod_operations41_multiple_flags_stay_deterministic(): void
    {
        $f = $this->facts();

        $f['financial_hold'] = true;
        $f['reversal_reported'] = true;

        $result = (new CheckoutCodOperationsPolicy())
            ->evaluate($f);

        $this->assertSame(
            ['financial_hold', 'reversal_reported'],
            $result['flags']
        );
    }

    public function test_cod_operations41_rejects_incomplete_facts(): void
    {
        $f = $this->facts();
        unset($f['bank_balanced']);

        $this->expectException(
            InvalidArgumentException::class
        );

        (new CheckoutCodOperationsPolicy())->evaluate($f);
    }

    public function test_cod_operations41_rejects_non_boolean_facts(): void
    {
        $f = $this->facts();
        $f['financial_hold'] = 1;

        $this->expectException(
            InvalidArgumentException::class
        );

        (new CheckoutCodOperationsPolicy())->evaluate($f);
    }
}
