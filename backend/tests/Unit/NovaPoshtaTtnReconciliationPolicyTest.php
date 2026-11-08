<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaTtnReconciliationPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaTtnReconciliationPolicyTest extends TestCase
{
    private NovaPoshtaTtnReconciliationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new NovaPoshtaTtnReconciliationPolicy();
    }

    private const NUMBER = '20450000000001';
    private const OTHER = '20450000000002';

    public function test_valid_number_remains_a_string(): void
    {
        $this->assertSame(self::NUMBER, $this->policy->number(self::NUMBER));
    }

    public function test_number_with_spaces_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->number(' ' . self::NUMBER);
    }

    public function test_short_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->number('20450000');
    }

    public function test_letters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->number('2045000000000A');
    }

    public function test_exact_single_match_is_observed_only(): void
    {
        $result = $this->policy->classify([['IntDocNumber' => self::NUMBER]], self::NUMBER);
        $this->assertSame('number_observed_once', $result['result']);
        $this->assertSame(1, $result['rows_examined']);
    }

    public function test_other_number_does_not_count_as_a_match(): void
    {
        $this->assertSame('number_absent_in_page',
            $this->policy->classify([['IntDocNumber' => self::OTHER]], self::NUMBER)['result']);
    }

    public function test_empty_page_is_a_page_level_negative_only(): void
    {
        $this->assertSame('number_absent_in_page', $this->policy->classify([], self::NUMBER)['result']);
    }

    public function test_duplicate_number_is_ambiguous(): void
    {
        $this->assertSame('duplicate_number', $this->policy->classify([
            ['IntDocNumber' => self::NUMBER], ['IntDocNumber' => self::NUMBER],
        ], self::NUMBER)['result']);
    }

    public function test_malformed_row_invalidates_a_match(): void
    {
        $this->assertSame('response_inconclusive', $this->policy->classify([
            ['IntDocNumber' => self::NUMBER], ['unexpected' => 'row'],
        ], self::NUMBER)['result']);
    }

    public function test_full_page_is_not_assumed_complete(): void
    {
        $rows = array_fill(0, 100, ['IntDocNumber' => self::OTHER]);
        $this->assertSame('response_inconclusive',
            $this->policy->classify($rows, self::NUMBER)['result']);
    }

    public function test_oversized_page_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->classify(array_fill(0, 101, []), self::NUMBER);
    }

    public function test_escalation_requires_positive_observation(): void
    {
        $this->policy->review('escalate_to_carrier', 'candidate_seen', 'number_observed_once');
        $this->addToAssertionCount(1);
    }

    public function test_escalation_cannot_invent_positive_observation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->review('escalate_to_carrier', 'candidate_seen', 'number_absent_in_page');
    }

    public function test_duplicate_result_may_be_escalated(): void
    {
        $this->policy->review('escalate_to_carrier', 'duplicate_number', 'duplicate_number');
        $this->addToAssertionCount(1);
    }

    public function test_keep_hold_accepts_inconclusive_scan(): void
    {
        $this->policy->review('keep_reconciliation_hold', 'insufficient_api_evidence', 'response_inconclusive');
        $this->addToAssertionCount(1);
    }

    public function test_unsupported_decision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->review('allow_retry', 'insufficient_api_evidence', 'response_inconclusive');
    }
}
