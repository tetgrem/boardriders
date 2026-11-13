<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodFinanceCenterCursor;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class CheckoutCodFinanceCenterCursorTest extends TestCase
{
    private function sample(): array
    {
        return [
            'priority' => 3,
            'observed_at' => '2026-11-13T15:42:20.123456Z',
            'source' => 'monitor',
            'source_id' => 31,
        ];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cod_finance49_cursor_roundtrip_preserves_all_sort_dimensions(): void
    {
        $s = app(CheckoutCodFinanceCenterCursor::class);
        $f = $s->fingerprint('all', 'all', false);

        $this->assertSame(
            $this->sample(),
            $s->decode(
                $s->encode($this->sample(), $f),
                $f
            )
        );
    }

    public function test_cod_finance49_cursor_fingerprint_binds_source(): void
    {
        $s = app(CheckoutCodFinanceCenterCursor::class);
        $a = $s->fingerprint('all', 'all', false);
        $b = $s->fingerprint('sla', 'all', false);

        $this->assertNotSame($a, $b);

        $this->expectException(InvalidArgumentException::class);

        $s->decode(
            $s->encode($this->sample(), $a),
            $b
        );
    }

    public function test_cod_finance49_cursor_fingerprint_binds_priority_filter(): void
    {
        $s = app(CheckoutCodFinanceCenterCursor::class);
        $a = $s->fingerprint('all', 'critical', false);
        $b = $s->fingerprint('all', 'high', false);

        $this->expectException(InvalidArgumentException::class);

        $s->decode(
            $s->encode($this->sample(), $a),
            $b
        );
    }

    public function test_cod_finance49_cursor_fingerprint_binds_acknowledgement_filter(): void
    {
        $s = app(CheckoutCodFinanceCenterCursor::class);
        $a = $s->fingerprint('all', 'all', false);
        $b = $s->fingerprint('all', 'all', true);

        $this->expectException(InvalidArgumentException::class);

        $s->decode(
            $s->encode($this->sample(), $a),
            $b
        );
    }

    public function test_cod_finance49_cursor_rejects_tampered_signature(): void
    {
        $s = app(CheckoutCodFinanceCenterCursor::class);
        $f = $s->fingerprint('all', 'all', false);
        $token = $s->encode($this->sample(), $f);

        $last = substr($token, -1);
        $tampered = substr($token, 0, -1)
            . ($last === '0' ? '1' : '0');

        $this->expectException(InvalidArgumentException::class);

        $s->decode($tampered, $f);
    }

    public function test_cod_finance49_cursor_expires_after_one_hour(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-11-13T15:00:00Z')
        );

        $s = app(CheckoutCodFinanceCenterCursor::class);
        $f = $s->fingerprint('all', 'all', false);

        $token = $s->encode($this->sample(), $f);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-11-13T16:00:01Z')
        );

        $this->expectException(InvalidArgumentException::class);

        $s->decode($token, $f);
    }

    public function test_cod_finance49_cursor_rejects_malformed_text(): void
    {
        $s = app(CheckoutCodFinanceCenterCursor::class);

        $this->expectException(InvalidArgumentException::class);

        $s->decode(
            'bad cursor',
            $s->fingerprint('all', 'all', false)
        );
    }

    public function test_cod_finance49_cursor_normalizes_time_to_utc(): void
    {
        $s = app(CheckoutCodFinanceCenterCursor::class);
        $f = $s->fingerprint('all', 'all', false);

        $item = $this->sample();
        $item['observed_at'] = '2026-11-13T17:42:20.123456+02:00';

        $decoded = $s->decode(
            $s->encode($item, $f),
            $f
        );

        $this->assertSame(
            '2026-11-13T15:42:20.123456Z',
            $decoded['observed_at']
        );
    }
}
