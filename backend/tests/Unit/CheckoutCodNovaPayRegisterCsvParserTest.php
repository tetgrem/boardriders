<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodNovaPayRegisterCsvParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodNovaPayRegisterCsvParserTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';

    private function header(): string
    {
        return implode(',', CheckoutCodNovaPayRegisterCsvParser::HEADERS);
    }

    private function row(
        string $order = self::A,
        string $shipment = '20450000000001',
        string $reference = 'PAYMENT_A40'
    ): string {
        return "$order,$shipment,$reference,2000,1320,680";
    }

    public function test_novapay_csv40_parses_one_row_with_integer_kopiykas(): void
    {
        $raw = $this->header() . "\n" . $this->row() . "\n";
        $r = (new CheckoutCodNovaPayRegisterCsvParser())->parse($raw);

        $this->assertSame(1, $r['row_count']);
        $this->assertSame(2000, $r['rows'][0]['gross_minor']);
        $this->assertSame(1320, $r['rows'][0]['fee_minor']);
        $this->assertSame(680, $r['rows'][0]['net_minor']);
        $this->assertSame(hash('sha256', $raw), $r['document_sha256']);
    }

    public function test_novapay_csv40_bom_and_crlf_are_accepted_and_raw_bytes_hashed(): void
    {
        $raw = "\xEF\xBB\xBF" . $this->header() . "\r\n" . $this->row() . "\r\n";
        $r = (new CheckoutCodNovaPayRegisterCsvParser())->parse($raw);

        $this->assertSame(self::A, $r['rows'][0]['order_key']);
        $this->assertSame(hash('sha256', $raw), $r['document_sha256']);
    }

    public function test_novapay_csv40_two_rows_preserve_each_order(): void
    {
        $raw = $this->header() . "\n"
            . $this->row(self::B, '20450000000002', 'PAYMENT_B40') . "\n"
            . $this->row() . "\n";

        $r = (new CheckoutCodNovaPayRegisterCsvParser())->parse($raw);

        $this->assertSame(2, $r['row_count']);
        $this->assertSame(self::B, $r['rows'][0]['order_key']);
        $this->assertSame(self::A, $r['rows'][1]['order_key']);
    }

    public function test_novapay_csv40_quoted_fields_are_supported(): void
    {
        $raw = $this->header() . "\n"
            . '"' . self::A . '","20450000000001","PAYMENT_A40","2000","1320","680"'
            . "\n";

        $this->assertSame(
            680,
            (new CheckoutCodNovaPayRegisterCsvParser())->parse($raw)['rows'][0]['net_minor']
        );
    }

    public function test_novapay_csv40_reordered_header_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            'shipment_number,order_key,external_payment_ref,gross_minor,fee_minor,net_minor'
            . "\n" . $this->row() . "\n"
        );
    }

    public function test_novapay_csv40_decimal_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n"
            . str_replace(',680', ',6.80', $this->row()) . "\n"
        );
    }

    public function test_novapay_csv40_leading_zero_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n"
            . str_replace(',680', ',0680', $this->row()) . "\n"
        );
    }

    public function test_novapay_csv40_large_amount_is_rejected_without_float(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n"
            . str_replace(',680', ',9999999999', $this->row()) . "\n"
        );
    }

    public function test_novapay_csv40_formula_reference_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n"
            . $this->row(self::A, '20450000000001', '=HYPERLINK') . "\n"
        );
    }

    public function test_novapay_csv40_extra_column_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n" . $this->row() . ',extra' . "\n"
        );
    }

    public function test_novapay_csv40_blank_row_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n" . $this->row() . "\n\n"
        );
    }

    public function test_novapay_csv40_invalid_utf8_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n" . $this->row() . "\xFF\n"
        );
    }

    public function test_novapay_csv40_nul_byte_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n" . $this->row() . "\0\n"
        );
    }

    public function test_novapay_csv40_1001_rows_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            $this->header() . "\n" . str_repeat($this->row() . "\n", 1001)
        );
    }

    public function test_novapay_csv40_file_over_512_kib_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodNovaPayRegisterCsvParser())->parse(
            str_repeat('x', 524289)
        );
    }
}
