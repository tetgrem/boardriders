<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PriceService
{
    /**
     * Отримати поточну ціну SKU.
     *
     * Грошові суми зберігаються в мінімальних
     * одиницях валюти (наприклад, копійках).
     */
    public function getPrice(
        int $variantId,
        string $priceListCode
    ): ?object {
        return DB::table('variant_prices as p')
            ->join(
                'price_lists as l',
                'l.id',
                '=',
                'p.price_list_id'
            )
            ->join(
                'currencies as c',
                'c.code',
                '=',
                'l.currency_code'
            )
            ->where('p.variant_id', $variantId)
            ->where('l.code', trim($priceListCode))
            ->where('l.is_active', true)
            ->where('c.is_active', true)
            ->select(
                'p.id',
                'p.variant_id',
                'p.price_minor',
                'p.compare_at_minor',
                'p.revision',
                'l.code as price_list_code',
                'l.currency_code',
                'c.minor_units'
            )
            ->first();
    }

    /**
     * Встановити або змінити ціну SKU.
     *
     * expectedRevision = 0 для першої ціни.
     *
     * Повертає ID запису в журналі змін.
     */
    public function setPrice(
        int $variantId,
        string $priceListCode,
        int $priceMinor,
        ?int $compareAtMinor,
        string $reference,
        string $changeKey,
        int $expectedRevision
    ): int {
        if (
            $variantId <= 0
            || $priceMinor < 0
            || $expectedRevision < 0
        ) {
            throw new InvalidArgumentException(
                'Некоректні параметри ціни або версії.'
            );
        }

        if (
            $compareAtMinor !== null
            && $compareAtMinor <= $priceMinor
        ) {
            throw new InvalidArgumentException(
                'Стара ціна має бути більшою за поточну.'
            );
        }

        $priceListCode = trim($priceListCode);
        $reference = trim($reference);

        if (
            $priceListCode === ''
            || $reference === ''
            || strlen($reference) > 150
        ) {
            throw new InvalidArgumentException(
                'Некоректний прайс-лист або документ.'
            );
        }

        if (!Str::isUuid($changeKey)) {
            throw new InvalidArgumentException(
                'Некоректний UUID зміни ціни.'
            );
        }

        $changeKey = strtolower($changeKey);

        return DB::transaction(function () use (
            $variantId,
            $priceListCode,
            $priceMinor,
            $compareAtMinor,
            $reference,
            $changeKey,
            $expectedRevision
        ): int {

            // Захищаємося від одночасного повтору UUID.
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['price-change:' . $changeKey]
            );

            $list = DB::table('price_lists as l')
                ->join(
                    'currencies as c',
                    'c.code',
                    '=',
                    'l.currency_code'
                )
                ->where('l.code', $priceListCode)
                ->select(
                    'l.id',
                    'l.is_active',
                    'c.is_active as currency_active'
                )
                ->first();

            if ($list === null) {
                throw new DomainException(
                    'Прайс-лист не знайдено.'
                );
            }

            // Перевірка повторного запиту.
            $existingChange = DB::table('variant_price_changes as h')
                ->join(
                    'variant_prices as p',
                    'p.id',
                    '=',
                    'h.variant_price_id'
                )
                ->where('h.change_key', $changeKey)
                ->select(
                    'h.*',
                    'p.variant_id',
                    'p.price_list_id'
                )
                ->first();

            if ($existingChange !== null) {
                if (
                    (int) $existingChange->variant_id !== $variantId
                    || (int) $existingChange->price_list_id !== (int) $list->id
                    || (int) $existingChange->new_price_minor !== $priceMinor
                    || (
                    $existingChange->new_compare_at_minor === null
                        ? null
                        : (int) $existingChange->new_compare_at_minor
                    ) !== $compareAtMinor
                    || $existingChange->reference !== $reference
                    || (int) $existingChange->previous_revision !== $expectedRevision
                ) {
                    throw new InvalidArgumentException(
                        'UUID зміни ціни вже використано '
                        . 'з іншими параметрами.'
                    );
                }

                return (int) $existingChange->id;
            }

            if (!$list->is_active || !$list->currency_active) {
                throw new DomainException(
                    'Прайс-лист або валюта неактивні.'
                );
            }

            // Серіалізуємо всі зміни одного SKU
            // в межах одного прайс-листа.
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['price-slot:' . $list->id . ':' . $variantId]
            );

            $current = DB::table('variant_prices')
                ->where('price_list_id', $list->id)
                ->where('variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            $currentRevision = $current === null
                ? 0
                : (int) $current->revision;

            if ($currentRevision !== $expectedRevision) {
                throw new DomainException(
                    'Ціну вже змінено іншим запитом. '
                    . 'Оновіть дані та повторіть дію.'
                );
            }

            $now = now();
            $newRevision = $currentRevision + 1;

            if ($current === null) {
                // Перша ціна для SKU.
                $priceId = DB::table('variant_prices')
                    ->insertGetId([
                        'variant_id' => $variantId,
                        'price_list_id' => $list->id,
                        'price_minor' => $priceMinor,
                        'compare_at_minor' => $compareAtMinor,
                        'revision' => $newRevision,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                // Зміна існуючої ціни.
                $priceId = (int) $current->id;

                DB::table('variant_prices')
                    ->where('id', $priceId)
                    ->update([
                        'price_minor' => $priceMinor,
                        'compare_at_minor' => $compareAtMinor,
                        'revision' => $newRevision,
                        'updated_at' => $now,
                    ]);
            }

            // Незмінний журнал історії цін.
            return (int) DB::table('variant_price_changes')
                ->insertGetId([
                    'change_key' => $changeKey,
                    'variant_price_id' => $priceId,
                    'previous_price_minor' => $current?->price_minor,
                    'new_price_minor' => $priceMinor,
                    'previous_compare_at_minor' => $current?->compare_at_minor,
                    'new_compare_at_minor' => $compareAtMinor,
                    'previous_revision' => $currentRevision,
                    'new_revision' => $newRevision,
                    'reference' => $reference,
                    'created_at' => $now,
                ]);
        }, 3);
    }
}
