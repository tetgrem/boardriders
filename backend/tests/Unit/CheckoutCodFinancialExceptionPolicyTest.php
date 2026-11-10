<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodFinancialExceptionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodFinancialExceptionPolicyTest extends TestCase
{
    public function test_financial_exception_accepts_reversal_report_on_batch(): void
    {
        (new CheckoutCodFinancialExceptionPolicy())->assertReport(
            'batch',
            'bank_reversal_reported'
        );

        $this->addToAssertionCount(1);
    }

    public function test_financial_exception_accepts_order_cancellation_report(): void
    {
        (new CheckoutCodFinancialExceptionPolicy())->assertReport(
            'novapay',
            'order_cancellation_reported'
        );

        $this->addToAssertionCount(1);
    }

    public function test_financial_exception_rejects_unknown_source(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialExceptionPolicy())->assertReport(
            'refund',
            'incorrect_amount'
        );
    }

    public function test_financial_exception_rejects_arbitrary_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialExceptionPolicy())->assertReport(
            'single',
            'refund_sent'
        );
    }

    public function test_financial_exception_pending_review_stays_held(): void
    {
        $this->assertSame(
            'pending_review_hold',
            (new CheckoutCodFinancialExceptionPolicy())->status(null)
        );
    }

    public function test_financial_exception_review_hold_is_not_refund(): void
    {
        $policy = new CheckoutCodFinancialExceptionPolicy();

        $policy->assertReview(
            'hold_for_investigation',
            'await_external_evidence'
        );

        $this->assertSame(
            'investigation_hold',
            $policy->status('hold_for_investigation')
        );
    }

    public function test_financial_exception_dismissal_requires_matching_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodFinancialExceptionPolicy())->assertReview(
            'dismiss_report',
            'await_external_evidence'
        );
    }

    public function test_financial_exception_dismissal_releases_status(): void
    {
        $policy = new CheckoutCodFinancialExceptionPolicy();

        $policy->assertReview(
            'dismiss_report',
            'report_not_supported'
        );

        $this->assertSame(
            'dismissed',
            $policy->status('dismiss_report')
        );
    }
}
