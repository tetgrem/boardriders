<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SupplierPriceService
{
    /**
     * Отримати закупівельну ціну постачальника
     * для конкретного SKU та валюти.
     */
    public function getPrice(
        int $supplierId,
        int $variantId,
        string $currencyCode
    ): ?object {
        return DB::table('supplier_prices')
            ->where('supplier_id', $supplierId)
            ->where('variant_id', $variantId)
            ->where(
                'currency_code',
                strtoupper(trim($currencyCode))
            )
            ->first();
    }

    /**
     * Створити або змінити закупівельну ціну.
     *
     * expectedRevision = 0 для першого запису.
     * Повертає ID запису історії.
     *
     * taxMode:
     * net   — ціна без податку
     * gross — ціна з податком
     */
    public function setPrice(
        int $supplierId,
        int $variantId,
        string $currencyCode,
        int $costMinor,
        string $taxMode,
        int $minOrderQuantity,
        string $reference,
        string $changeKey,
        int $expectedRevision
    ): int {
        $currencyCode = strtoupper(trim($currencyCode));
        $taxMode = strtolower(trim($taxMode));
        $reference = trim($reference);

        if (
            $supplierId <= 0
            || $variantId <= 0
            || $costMinor <= 0
            || $minOrderQuantity <= 0
            || $expectedRevision < 0
            || !preg_match('/^[A-Z]{3}$/', $currencyCode)
            || !in_array($taxMode, ['net', 'gross'], true)
            || $reference === ''
            || strlen($reference) > 150
            || !Str::isUuid($changeKey)
        ) {
            throw new InvalidArgumentException(
                'Некоректні параметри закупівельної ціни.'
            );
        }

        $changeKey = strtolower($changeKey);

        return DB::transaction(function () use (
            $supplierId,
            $variantId,
            $currencyCode,
            $costMinor,
            $taxMode,
            $minOrderQuantity,
            $reference,
            $changeKey,
            $expectedRevision
        ): int {
            /*
             * Захист від повторних запитів
             * з однаковим UUID.
             */
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['supplier-price-change:' . $changeKey]
            );

            $existing = DB::table('supplier_price_changes as h')
                ->join(
                    'supplier_prices as p',
                    'p.id',
                    '=',
                    'h.supplier_price_id'
                )
                ->where('h.change_key', $changeKey)
                ->select(
                    'h.*',
                    'p.supplier_id',
                    'p.variant_id',
                    'p.currency_code'
                )
                ->first();

            if ($existing !== null) {
                if (
                    (int) $existing->supplier_id !== $supplierId
                    || (int) $existing->variant_id !== $variantId
                    || $existing->currency_code !== $currencyCode
                    || (int) $existing->new_cost_minor !== $costMinor
                    || $existing->new_tax_mode !== $taxMode
                    || (int) $existing->new_min_order_quantity !== $minOrderQuantity
                    || $existing->reference !== $reference
                    || (int) $existing->previous_revision !== $expectedRevision
                ) {
                    throw new InvalidArgumentException(
                        'UUID зміни закупівельної ціни вже '
                        . 'використано з іншими параметрами.'
                    );
                }

                return (int) $existing->id;
            }

            /*
             * Перевіряємо постачальника та валюту.
             */
            $supplier = DB::table('suppliers')
                ->where('id', $supplierId)
                ->first();

            $currency = DB::table('currencies')
                ->where('code', $currencyCode)
                ->first();

            if (
                !$supplier
                || !$supplier->is_active
                || !$currency
                || !$currency->is_active
            ) {
                throw new DomainException(
                    'Постачальник або валюта неактивні чи відсутні.'
                );
            }

            /*
             * Блокуємо ціну конкретного постачальника
             * та SKU навіть при першому створенні.
             */
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [
                    "supplier-price-slot:{$supplierId}:{$variantId}:{$currencyCode}"
                ]
            );

            $current = DB::table('supplier_prices')
                ->where('supplier_id', $supplierId)
                ->where('variant_id', $variantId)
                ->where('currency_code', $currencyCode)
                ->lockForUpdate()
                ->first();

            $revision = $current === null
                ? 0
                : (int) $current->revision;

            if ($revision !== $expectedRevision) {
                throw new DomainException(
                    'Закупівельну ціну вже змінено. '
                    . 'Оновіть дані та повторіть дію.'
                );
            }

            $now = now();

            /*
             * Створюємо або оновлюємо ціну.
             */
            if ($current === null) {
                $priceId = DB::table('supplier_prices')
                    ->insertGetId([
                        'supplier_id' => $supplierId,
                        'variant_id' => $variantId,
                        'currency_code' => $currencyCode,
                        'cost_minor' => $costMinor,
                        'tax_mode' => $taxMode,
                        'min_order_quantity' => $minOrderQuantity,
                        'revision' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                $priceId = (int) $current->id;

                DB::table('supplier_prices')
                    ->where('id', $priceId)
                    ->update([
                        'cost_minor' => $costMinor,
                        'tax_mode' => $taxMode,
                        'min_order_quantity' => $minOrderQuantity,
                        'revision' => $revision + 1,
                        'updated_at' => $now,
                    ]);
            }

            /*
             * Зберігаємо історію зміни.
             */
            return (int) DB::table('supplier_price_changes')
                ->insertGetId([
                    'change_key' => $changeKey,
                    'supplier_price_id' => $priceId,
                    'previous_cost_minor' => $current?->cost_minor,
                    'new_cost_minor' => $costMinor,
                    'previous_tax_mode' => $current?->tax_mode,
                    'new_tax_mode' => $taxMode,
                    'previous_min_order_quantity' =>
                        $current?->min_order_quantity,
                    'new_min_order_quantity' => $minOrderQuantity,
                    'previous_revision' => $revision,
                    'new_revision' => $revision + 1,
                    'reference' => $reference,
                    'created_at' => $now,
                ]);
        }, 3);
    }
}
