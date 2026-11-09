<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodTtnEvidenceMatrixPolicy;
use PHPUnit\Framework\TestCase;

final class CheckoutCodTtnEvidenceMatrixPolicyTest extends TestCase
{
    private CheckoutCodTtnEvidenceMatrixPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CheckoutCodTtnEvidenceMatrixPolicy();
    }

    private function scan(array $changes = []): array
    {
        return array_replace([
            'provider_result' => 'number_observed_once',
            'number_hmac' => str_repeat('a', 64),
            'context_matches' => true,
            'review_decision' => 'escalate_to_carrier',
            'comparison_summary' => 'snapshot_consistent',
            'document_ref_seen' => true,
        ], $changes);
    }

    public function test_empty_history_is_insufficient(): void
    {
        $result = $this->policy->classify([]);

        $this->assertSame(
            'insufficient_evidence',
            $result['outcome']
        );
    }

    public function test_consistent_document_is_only_a_candidate_snapshot(): void
    {
        $result = $this->policy->classify([
            $this->scan(),
        ]);

        $this->assertSame(
            'candidate_snapshot_consistent',
            $result['outcome']
        );

        $this->assertFalse($result['carrier_ownership_verified']);
        $this->assertFalse($result['ttn_submission_allowed']);
    }

    public function test_absent_first_page_does_not_prove_absence(): void
    {
        $result = $this->policy->classify([
            $this->scan([
                'provider_result' => 'number_absent_in_page',
                'comparison_summary' => null,
                'document_ref_seen' => false,
            ]),
        ]);

        $this->assertSame(
            'insufficient_evidence',
            $result['outcome']
        );

        $this->assertFalse($result['absence_proven']);
    }

    public function test_duplicate_number_is_conflict(): void
    {
        $result = $this->policy->classify([
            $this->scan([
                'provider_result' => 'duplicate_number',
            ]),
        ]);

        $this->assertSame(
            'conflicting_evidence',
            $result['outcome']
        );
    }

    public function test_snapshot_mismatch_is_conflict(): void
    {
        $result = $this->policy->classify([
            $this->scan([
                'comparison_summary' => 'snapshot_mismatch',
            ]),
        ]);

        $this->assertSame(
            'conflicting_evidence',
            $result['outcome']
        );
    }

    public function test_stale_context_is_conflict(): void
    {
        $result = $this->policy->classify([
            $this->scan([
                'context_matches' => false,
            ]),
        ]);

        $this->assertSame(
            'conflicting_evidence',
            $result['outcome']
        );
    }

    public function test_distinct_candidate_numbers_are_conflict(): void
    {
        $result = $this->policy->classify([
            $this->scan(),
            $this->scan([
                'number_hmac' => str_repeat('b', 64),
            ]),
        ]);

        $this->assertSame(
            'conflicting_evidence',
            $result['outcome']
        );
    }

    public function test_missing_review_is_insufficient(): void
    {
        $result = $this->policy->classify([
            $this->scan([
                'review_decision' => null,
            ]),
        ]);

        $this->assertSame(
            'insufficient_evidence',
            $result['outcome']
        );
    }

    public function test_missing_document_reference_is_insufficient(): void
    {
        $result = $this->policy->classify([
            $this->scan([
                'document_ref_seen' => false,
            ]),
        ]);

        $this->assertSame(
            'insufficient_evidence',
            $result['outcome']
        );
    }

    public function test_no_history_allows_retry_never(): void
    {
        $result = $this->policy->classify([]);

        $this->assertFalse($result['automatic_retry_allowed']);
        $this->assertFalse($result['ttn_submission_allowed']);
    }
}
