<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodFinancialResolutionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodFinancialResolutionPolicyTest extends TestCase
{
    public function test_resolution_valid_document_hash_is_metadata_only(): void
    {
        (new CheckoutCodFinancialResolutionPolicy())->evidence(
            'bank_notice_claim',
            str_repeat('a', 64)
        );

        $this->addToAssertionCount(1);
    }

    public function test_resolution_rejects_unhashed_document_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialResolutionPolicy())->evidence(
            'bank_notice_claim',
            'bank-ref-123'
        );
    }

    public function test_resolution_rejects_unknown_evidence_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialResolutionPolicy())->evidence(
            'payment_confirmed',
            str_repeat('a', 64)
        );
    }

    public function test_resolution_allows_signed_negative_kopiyka_delta_by_recommendation(): void
    {
        (new CheckoutCodFinancialResolutionPolicy())->proposal(
            'single',
            'incorrect_amount',
            'amount_correction',
            680,
            600
        );

        $this->addToAssertionCount(1);
    }

    public function test_resolution_reversal_recommendation_requires_zero_target(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialResolutionPolicy())->proposal(
            'batch',
            'bank_reversal_reported',
            'reported_reversal',
            680,
            1
        );
    }

    public function test_resolution_reallocation_only_for_batches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialResolutionPolicy())->proposal(
            'single',
            'wrong_source_reference',
            'batch_reallocation',
            680,
            680
        );
    }

    public function test_resolution_return_followup_does_not_precalculate_refund(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialResolutionPolicy())->proposal(
            'novapay',
            'customer_return_reported',
            'return_case_followup',
            680,
            0
        );
    }

    public function test_resolution_review_can_require_rework(): void
    {
        (new CheckoutCodFinancialResolutionPolicy())->review(
            'return_for_rework',
            'insufficient_consistency'
        );

        $this->addToAssertionCount(1);
    }

    public function test_resolution_rejects_payment_execution_decision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialResolutionPolicy())->review(
            'execute_refund',
            'approved'
        );
    }
}
