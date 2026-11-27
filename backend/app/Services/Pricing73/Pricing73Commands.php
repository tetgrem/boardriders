<?php

declare(strict_types=1);

namespace App\Services\Pricing73;

use App\Services\Pricing\PriceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Pricing73Commands
{
    public function __construct(
        private readonly Pricing73Audit $audit,
        private readonly PriceService $prices
    ) {}

    public function submit(
        string $key,
        string $action,
        array $data
    ): array {
        return $this->audit->execute(
            $key,
            $action,
            $data,
            fn (): array => match ($action) {
                'prices_save' => $this->savePrices($key, $data),
                'promotion_create' => $this->createPromotion($data),
                'promotion_toggle' => $this->togglePromotion($data),
                'code_create' => $this->createCode($data),
                'code_toggle' => $this->toggleCode($data),
                'market_create' => $this->createMarket($data),
                'policy_create' => $this->createPolicy($data),
                default => throw new DomainException(
                    'Unknown pricing operation'
                ),
            }
        );
    }

    private function priceList(int $id): object
    {
        $row = DB::table('price_lists as pl')
            ->join('currencies as c', 'c.code', '=', 'pl.currency_code')
            ->where('pl.id', $id)
            ->where('pl.type', 'retail')
            ->where('pl.is_active', true)
            ->where('c.is_active', true)
            ->first([
                'pl.id',
                'pl.code',
                'pl.country_code',
                'pl.currency_code',
                'c.minor_units',
            ]);

        if ($row === null) {
            throw new DomainException(
                'Розничный прайс-лист недоступен'
            );
        }

        return $row;
    }

    private function savePrices(
        string $key,
        array $data
    ): array {
        $list = $this->priceList((int) $data['price_list_id']);

        $lines = $data['lines'];

        usort(
            $lines,
            static fn (array $a, array $b): int =>
                (int) $a['variant_id'] <=> (int) $b['variant_id']
        );

        $seen = [];
        $results = [];

        foreach ($lines as $line) {
            $id = (int) $line['variant_id'];
            $price = (int) $line['price_minor'];

            $compare = isset($line['compare_at_minor'])
                ? (int) $line['compare_at_minor'] : null;

            if (isset($seen[$id])) {
                throw new DomainException('Повторяющийся SKU');
            }

            $seen[$id] = true;

            if (!DB::table('product_variants as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('v.id', $id)
                ->whereNull('p.deleted_at')
                ->exists()) {
                throw new DomainException(
                    'Вариант товара недоступен'
                );
            }

            if ($compare !== null && $compare <= $price) {
                throw new DomainException(
                    'Старая цена должна быть выше новой'
                );
            }

            $changeKey = (string) Str::uuid();

            $historyId = $this->prices->setPrice(
                $id,
                (string) $list->code,
                $price,
                $compare,
                'PR73-' . $key,
                $changeKey,
                (int) $line['expected_revision']
            );

            $results[] = [
                'variant_id' => $id,
                'price_minor' => $price,
                'compare_at_minor' => $compare,
                'revision' => (int) $line['expected_revision'] + 1,
                'history_id' => $historyId,
                'change_key' => $changeKey,
            ];
        }

        return [
            'price_list_id' => (int) $list->id,
            'currency_code' => $list->currency_code,
            'line_count' => count($results),
            'lines' => $results,
        ];
    }

    private function createPromotion(array $data): array
    {
        $list = $this->priceList((int) $data['price_list_id']);

        $type = $data['type'];
        $value = (int) $data['value'];

        if ($type === 'percent' && $value > 10000) {
            throw new DomainException(
                'Процент скидки не может превышать 100%'
            );
        }

        $starts = isset($data['starts_at'])
            ? CarbonImmutable::parse($data['starts_at'])->utc()
            : null;

        $ends = isset($data['ends_at'])
            ? CarbonImmutable::parse($data['ends_at'])->utc()
            : null;

        if ($starts !== null
            && $ends !== null
            && !$starts->lt($ends)) {
            throw new DomainException(
                'Дата окончания должна быть позже начала'
            );
        }

        if ($ends !== null && $ends->lte(now())) {
            throw new DomainException(
                'Нельзя создать уже завершённую акцию'
            );
        }

        $id = (int) DB::table('promotions')->insertGetId([
            'price_list_id' => (int) $list->id,
            'name' => trim($data['name']),
            'type' => $type,
            'value' => $value,
            'min_subtotal_minor' => (int) ($data['min_subtotal_minor'] ?? 0),
            'max_discount_minor' => $data['max_discount_minor'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'starts_at' => $starts?->format('Y-m-d H:i:s.uP'),
            'ends_at' => $ends?->format('Y-m-d H:i:s.uP'),

            // New promotions always start disabled.
            'is_active' => false,
            'admin_revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $code = $this->insertCode(
            $id,
            $data['code'] ?? null,
            $data['code_usage_limit'] ?? null
        );

        return [
            'promotion_id' => $id,
            'revision' => 1,
            'is_active' => false,
            ...$code,
        ];
    }

    private function lockPromotion(array $data): object
    {
        $row = DB::table('promotions')
            ->where('id', $data['promotion_id'])
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            abort(404, 'Promotion not found');
        }

        if ((int) $row->admin_revision
            !== (int) $data['expected_revision']
            || (int) $row->admin_revision >= 2147483646) {
            throw new DomainException(
                'Акция изменилась. Обновите данные'
            );
        }

        return $row;
    }

    private function bumpPromotion(object $row): int
    {
        $next = (int) $row->admin_revision + 1;

        DB::table('promotions')
            ->where('id', $row->id)
            ->update([
                'admin_revision' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }

    private function togglePromotion(array $data): array
    {
        $row = $this->lockPromotion($data);
        $active = (bool) $data['is_active'];

        if ($active) {
            $this->priceList((int) $row->price_list_id);

            if ($row->ends_at !== null
                && CarbonImmutable::parse($row->ends_at)->lte(now())) {
                throw new DomainException('Срок акции истёк');
            }

            if (!DB::table('promotion_codes')
                ->where('promotion_id', $row->id)
                ->where('is_active', true)
                ->exists()) {
                throw new DomainException(
                    'Для включения нужен активный промокод'
                );
            }
        }

        DB::table('promotions')
            ->where('id', $row->id)
            ->update(['is_active' => $active]);

        return [
            'promotion_id' => (int) $row->id,
            'revision' => $this->bumpPromotion($row),
            'is_active' => $active,
        ];
    }

    private function insertCode(
        int $promotionId,
        ?string $requested,
        ?int $limit
    ): array {
        $code = strtoupper(trim($requested ?? ''));

        if ($code === '') {
            do {
                $code = 'BDR-' . strtoupper(bin2hex(random_bytes(6)));
            } while (DB::table('promotion_codes')
                ->where('code', $code)->exists());
        }

        if (!preg_match(
            '/^[A-Z0-9][A-Z0-9_-]{2,39}$/D',
            $code
        )) {
            throw new DomainException(
                'Некорректный формат промокода'
            );
        }

        $id = (int) DB::table('promotion_codes')->insertGetId([
            'promotion_id' => $promotionId,
            'code' => $code,
            'is_active' => true,
            'usage_limit' => $limit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'code_id' => $id,
            'code' => $code,
        ];
    }

    private function createCode(array $data): array
    {
        $promotion = $this->lockPromotion($data);

        $code = $this->insertCode(
            (int) $promotion->id,
            $data['code'] ?? null,
            isset($data['usage_limit'])
                ? (int) $data['usage_limit'] : null
        );

        return [
            'promotion_id' => (int) $promotion->id,
            'revision' => $this->bumpPromotion($promotion),
            ...$code,
        ];
    }

    private function toggleCode(array $data): array
    {
        // The same locking order as PromotionReservationService:
        // promotion first, code second.
        $promotion = $this->lockPromotion($data);

        $code = DB::table('promotion_codes')
            ->where('id', $data['code_id'])
            ->where('promotion_id', $promotion->id)
            ->lockForUpdate()
            ->first();

        if ($code === null) {
            abort(404, 'Promotion code not found');
        }

        $active = (bool) $data['is_active'];

        DB::table('promotion_codes')
            ->where('id', $code->id)
            ->update([
                'is_active' => $active,
                'updated_at' => now(),
            ]);

        return [
            'promotion_id' => (int) $promotion->id,
            'code_id' => (int) $code->id,
            'revision' => $this->bumpPromotion($promotion),
            'is_active' => $active,
        ];
    }

    private function createMarket(array $data): array
    {
        $list = $this->priceList((int) $data['price_list_id']);

        $id = (int) DB::table('markets')->insertGetId([
            'code' => strtoupper($data['code']),
            'name' => trim($data['name']),
            'country_code' => $list->country_code,
            'currency_code' => $list->currency_code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'market_id' => $id,
            'code' => strtoupper($data['code']),
            'currency_code' => $list->currency_code,
        ];
    }

    private function createPolicy(array $data): array
    {
        $market = DB::table('markets')
            ->where('id', $data['market_id'])
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($market === null) {
            throw new DomainException('Рынок недоступен');
        }

        if (!DB::table('currencies')
            ->where('code', $market->currency_code)
            ->where('is_active', true)
            ->exists()) {
            throw new DomainException('Валюта рынка недоступна');
        }

        $latest = DB::table('loyalty_redemption_policies')
            ->where('market_id', $market->id)
            ->orderByDesc('effective_from')
            ->first();

        $latestId = $latest === null ? null : (int) $latest->id;

        $expected = isset($data['expected_policy_id'])
            ? (int) $data['expected_policy_id'] : null;

        if ($latestId !== $expected) {
            throw new DomainException(
                'Политика лояльности изменилась. Обновите данные'
            );
        }

        $databaseNow = CarbonImmutable::parse(
            DB::selectOne('SELECT clock_timestamp() AS ts')->ts
        )->utc();

        if ($latest !== null
            && CarbonImmutable::parse($latest->effective_from)
                ->gt($databaseNow)) {
            throw new DomainException(
                'Уже есть запланированная версия. Дождитесь её активации'
            );
        }

        $rules = $data['rules'];
        $enabled = (bool) $data['is_enabled'];

        if ($enabled && !collect($rules)->contains(
                fn (array $rule): bool => $rule['decision'] === 'allow'
            )) {
            throw new DomainException(
                'В активной политике нужно хотя бы одно разрешающее правило'
            );
        }

        $scopeTables = [
            'brand' => 'brands',
            'category' => 'categories',
            'product' => 'products',
            'variant' => 'product_variants',
        ];

        $seen = [];
        $prepared = [];

        foreach ($rules as $rule) {
            $scope = $rule['scope_type'];

            $scopeId = isset($rule['scope_id'])
                ? (int) $rule['scope_id'] : null;

            $unique = $scope . ':' . ($scopeId ?? 'all');

            if (isset($seen[$unique])) {
                throw new DomainException(
                    'Одинаковая область правила указана дважды'
                );
            }

            $seen[$unique] = true;

            $fields = [
                'brand_id' => null,
                'category_id' => null,
                'product_id' => null,
                'variant_id' => null,
            ];

            if ($scope === 'all') {
                if ($scopeId !== null) {
                    throw new DomainException(
                        'Для правила «Все товары» ID не нужен'
                    );
                }
            } else {
                if ($scopeId === null
                    || !isset($scopeTables[$scope])
                    || !DB::table($scopeTables[$scope])
                        ->where('id', $scopeId)->exists()) {
                    throw new DomainException(
                        'Объект правила не найден'
                    );
                }

                $fields[$scope . '_id'] = $scopeId;
            }

            $prepared[] = [
                'scope_type' => $scope,
                'decision' => $rule['decision'],
                'priority' => (int) $rule['priority'],
                ...$fields,
            ];
        }

        // Eligibility rules must be inserted before the
        // effective_from timestamp according to PostgreSQL triggers.
        $effective = $databaseNow->addSeconds(
            max(
                120,
                (int) config('pricing73.policy_activation_delay_seconds', 120)
            )
        );

        $version = 'PR73-' . Str::uuid();

        $id = (int) DB::table('loyalty_redemption_policies')
            ->insertGetId([
                'market_id' => (int) $market->id,
                'currency_code' => $market->currency_code,
                'program_code' => trim($data['program_code']),
                'rule_version' => $version,
                'is_enabled' => $enabled,

                'bonus_units_per_step' => (int) $data['bonus_units_per_step'],
                'money_minor_per_step' => (int) $data['money_minor_per_step'],
                'max_goods_share_bps' => (int) $data['max_goods_share_bps'],

                'max_discount_minor' => $data['max_discount_minor'] ?? null,
                'minimum_cash_minor' => (int) $data['minimum_cash_minor'],

                'effective_from' => $effective->format('Y-m-d H:i:s.uP'),
                'created_at' => $databaseNow->format('Y-m-d H:i:s.uP'),
            ]);

        foreach ($prepared as $rule) {
            DB::table('loyalty_redemption_eligibility_rules')->insert([
                'policy_id' => $id,
                ...$rule,
                'created_at' => $databaseNow->format('Y-m-d H:i:s.uP'),
            ]);
        }

        return [
            'policy_id' => $id,
            'market_id' => (int) $market->id,
            'rule_version' => $version,
            'effective_from' => $effective->format('Y-m-d\TH:i:s.u\Z'),
            'is_enabled' => $enabled,
            'rules_count' => count($prepared),
        ];
    }
}
