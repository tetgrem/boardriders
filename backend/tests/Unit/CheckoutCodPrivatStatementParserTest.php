<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodPrivatStatementParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodPrivatStatementParserTest extends TestCase
{
    private const IBAN = 'UA111111111111111111111111111';

    private function row(array $overrides = []): array
    {
        return array_merge([
            'AUT_MY_ACC' => self::IBAN,
            'FL_REAL' => 'r',
            'PR_PR' => 'r',
            'CCY' => 'UAH',
            'TRANTYPE' => 'C',
            'REF' => 'BANKREF_TEST_30',
            'REFN' => '1',
            'SUM' => '6.80',
            'DAT_OD' => '09.11.2026',
        ], $overrides);
    }

    private function page(
        array $rows,
        mixed $more = false,
        mixed $cursor = null
    ): array {
        return [
            'status' => 'SUCCESS',
            'type' => 'transactions',
            'exist_next_page' => $more,
            'next_page_id' => $cursor,
            'transactions' => $rows,
        ];
    }

    public function test_privat_statement30_parses_posted_credit(): void
    {
        $p = (new CheckoutCodPrivatStatementParser())->page(
            $this->page([$this->row()]),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        );

        $this->assertSame('credit', $p['rows'][0]['direction']);
        $this->assertSame(680, $p['rows'][0]['amount_minor']);
        $this->assertSame('2026-11-09', $p['rows'][0]['booked_on']);
    }

    public function test_privat_statement30_skips_unposted_and_unreal_rows(): void
    {
        $p = (new CheckoutCodPrivatStatementParser())->page(
            $this->page([
                $this->row(['PR_PR' => 'p']),
                $this->row(['FL_REAL' => 'i']),
            ]),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        );

        $this->assertSame([], $p['rows']);
        $this->assertSame(2, $p['skipped_count']);
    }

    public function test_privat_statement30_debit_and_negative_decimal(): void
    {
        $p = (new CheckoutCodPrivatStatementParser())->page(
            $this->page([
                $this->row([
                    'TRANTYPE' => 'D',
                    'SUM' => '-2.50',
                ]),
            ]),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        );

        $this->assertSame('debit', $p['rows'][0]['direction']);
        $this->assertSame(250, $p['rows'][0]['amount_minor']);
    }

    public function test_privat_statement30_rejects_float_and_precision_loss(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodPrivatStatementParser())->minor('1.001');
    }

    public function test_privat_statement30_rejects_other_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodPrivatStatementParser())->page(
            $this->page([
                $this->row([
                    'AUT_MY_ACC' => 'UA222222222222222222222222222',
                ]),
            ]),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        );
    }

    public function test_privat_statement30_rejects_invalid_pagination(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodPrivatStatementParser())->page(
            $this->page([], true, null),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        );
    }

    public function test_privat_statement30_rejects_out_of_period(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodPrivatStatementParser())->page(
            $this->page([
                $this->row(['DAT_OD' => '08.11.2026']),
            ]),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        );
    }

    public function test_privat_statement30_accepts_boolean_and_text_pagination_flags(): void
    {
        $parser = new CheckoutCodPrivatStatementParser();

        $this->assertTrue($parser->page(
            $this->page([], 'true', 'CURSOR_30'),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        )['has_more']);

        $this->assertFalse($parser->page(
            $this->page([], false),
            self::IBAN,
            '2026-11-09',
            '2026-11-09'
        )['has_more']);
    }
}
