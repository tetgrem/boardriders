<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutOrderReturnPolicy;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutOrderReturnPolicyTest extends TestCase
{
    private function policy(): CheckoutOrderReturnPolicy
    {
        return new CheckoutOrderReturnPolicy();
    }

    private function snapshot(): array
    {
        return ['schema_version' => 1, 'lines' => [
            ['variant_id' => 11, 'quantity' => 2, 'units' => [
                ['unit_index' => 1, 'gross_minor' => 1001,
                    'promotion_discount_minor' => 11, 'loyalty_discount_minor' => 31, 'cash_minor' => 959],
                ['unit_index' => 2, 'gross_minor' => 1001,
                    'promotion_discount_minor' => 10, 'loyalty_discount_minor' => 30, 'cash_minor' => 961],
            ]],
        ]];
    }

    public function test_cod_return52_normalizes_variant_order(): void
    {
        self::assertSame([11 => 1, 22 => 2], $this->policy()->normalize([
            ['variant_id' => 22, 'quantity' => 2], ['variant_id' => 11, 'quantity' => 1],
        ]));
    }

    public function test_cod_return52_rejects_duplicate_variant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy()->normalize([
            ['variant_id' => 11, 'quantity' => 1], ['variant_id' => 11, 'quantity' => 1],
        ]);
    }

    public function test_cod_return52_rejects_zero_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy()->normalize([['variant_id' => 11, 'quantity' => 0]]);
    }

    public function test_cod_return52_first_unit_preserves_money_and_bonus(): void
    {
        $q = $this->policy()->allocate($this->snapshot(), [11 => 1], []);
        self::assertSame(959, $q['totals']['cash_minor']);
        self::assertSame(31, $q['totals']['loyalty_minor']);
        self::assertSame(11, $q['totals']['promotion_minor']);
        self::assertSame(1, $q['units'][0]['unit_index']);
    }

    public function test_cod_return52_second_unit_uses_own_cent_allocation(): void
    {
        $q = $this->policy()->allocate($this->snapshot(), [11 => 1], [11 => [1 => true]]);
        self::assertSame(961, $q['totals']['cash_minor']);
        self::assertSame(2, $q['units'][0]['unit_index']);
    }

    public function test_cod_return52_full_return_reconciles_unit_totals(): void
    {
        $q = $this->policy()->allocate($this->snapshot(), [11 => 2], []);
        self::assertSame(2002, $q['totals']['gross_minor']);
        self::assertSame(1920, $q['totals']['cash_minor']);
        self::assertSame(61, $q['totals']['loyalty_minor']);
        self::assertSame(21, $q['totals']['promotion_minor']);
    }

    public function test_cod_return52_cannot_allocate_already_claimed_unit(): void
    {
        $this->expectException(DomainException::class);
        $this->policy()->allocate($this->snapshot(), [11 => 2], [11 => [1 => true]]);
    }

    public function test_cod_return52_cannot_return_foreign_variant(): void
    {
        $this->expectException(DomainException::class);
        $this->policy()->allocate($this->snapshot(), [22 => 1], []);
    }

    public function test_cod_return52_detects_corrupt_unit_amount(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['lines'][0]['units'][0]['cash_minor']++;
        $this->expectException(DomainException::class);
        $this->policy()->allocate($snapshot, [11 => 1], []);
    }

    public function test_cod_return52_inspection_counts_only_received_goods(): void
    {
        $q = $this->policy()->allocate($this->snapshot(), [11 => 2], []);
        $units = array_map(static fn (array $u, int $i): array => ['id' => $i + 1] + $u,
            $q['units'], array_keys($q['units']));
        $r = $this->policy()->inspect($units, [
            ['variant_id' => 11, 'unit_index' => 1, 'condition' => 'damaged'],
            ['variant_id' => 11, 'unit_index' => 2, 'condition' => 'not_received'],
        ]);
        self::assertSame(1, $r['totals']['units']);
        self::assertSame(1, $r['totals']['not_received_units']);
        self::assertSame(959, $r['totals']['cash_minor']);
    }

    public function test_cod_return52_inspection_rejects_unclassified_units(): void
    {
        $q = $this->policy()->allocate($this->snapshot(), [11 => 2], []);
        $units = array_map(static fn (array $u, int $i): array => ['id' => $i + 1] + $u,
            $q['units'], array_keys($q['units']));
        $this->expectException(InvalidArgumentException::class);
        $this->policy()->inspect($units, [
            ['variant_id' => 11, 'unit_index' => 1, 'condition' => 'resellable'],
        ]);
    }

    public function test_cod_return52_inspection_rejects_duplicate_unit(): void
    {
        $q = $this->policy()->allocate($this->snapshot(), [11 => 2], []);
        $units = array_map(static fn (array $u, int $i): array => ['id' => $i + 1] + $u,
            $q['units'], array_keys($q['units']));
        $this->expectException(InvalidArgumentException::class);
        $this->policy()->inspect($units, [
            ['variant_id' => 11, 'unit_index' => 1, 'condition' => 'resellable'],
            ['variant_id' => 11, 'unit_index' => 1, 'condition' => 'resellable'],
        ]);
    }
}
