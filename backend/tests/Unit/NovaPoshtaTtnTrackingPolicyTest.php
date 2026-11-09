<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaTtnTrackingPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaTtnTrackingPolicyTest extends TestCase
{
    private const NUMBER = '20450000000001';

    private function policy(): NovaPoshtaTtnTrackingPolicy
    {
        return new NovaPoshtaTtnTrackingPolicy();
    }

    public function test_exact_match_preserves_provider_code(): void
    {
        $this->assertSame(
            ['outcome' => 'observed', 'status_code' => '9'],
            $this->policy()->classify([
                [
                    'Number' => self::NUMBER,
                    'StatusCode' => '9',
                    'Status' => 'Do not persist arbitrary status text',
                ],
            ], self::NUMBER)
        );
    }

    public function test_integer_status_code_is_normalized(): void
    {
        $this->assertSame(
            '7',
            $this->policy()->classify([
                [
                    'Number' => self::NUMBER,
                    'StatusCode' => 7,
                ],
            ], self::NUMBER)['status_code']
        );
    }

    public function test_empty_response_is_inconclusive(): void
    {
        $this->assertSame(
            'inconclusive',
            $this->policy()->classify([], self::NUMBER)['outcome']
        );
    }

    public function test_wrong_number_never_matches(): void
    {
        $this->assertNull(
            $this->policy()->classify([
                [
                    'Number' => '20450000000002',
                    'StatusCode' => '9',
                ],
            ], self::NUMBER)['status_code']
        );
    }

    public function test_duplicate_rows_are_inconclusive(): void
    {
        $row = [
            'Number' => self::NUMBER,
            'StatusCode' => '9',
        ];

        $this->assertSame(
            'inconclusive',
            $this->policy()->classify(
                [$row, $row],
                self::NUMBER
            )['outcome']
        );
    }

    public function test_missing_status_code_is_inconclusive(): void
    {
        $this->assertSame(
            'inconclusive',
            $this->policy()->classify([
                ['Number' => self::NUMBER],
            ], self::NUMBER)['outcome']
        );
    }

    public function test_malformed_status_is_inconclusive(): void
    {
        $this->assertNull(
            $this->policy()->classify([
                [
                    'Number' => self::NUMBER,
                    'StatusCode' => 'delivered',
                ],
            ], self::NUMBER)['status_code']
        );
    }

    public function test_unexpected_digits_are_not_coerced(): void
    {
        $this->assertSame(
            'inconclusive',
            $this->policy()->classify([
                [
                    'Number' => self::NUMBER,
                    'StatusCode' => ' 9 ',
                ],
            ], self::NUMBER)['outcome']
        );
    }

    public function test_invalid_input_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy()->classify([], 'abc');
    }
}
