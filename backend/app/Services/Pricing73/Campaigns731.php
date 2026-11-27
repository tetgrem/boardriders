<?php

declare(strict_types=1);

namespace App\Services\Pricing73;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class Campaigns731
{
    public function __construct(
        private readonly Pricing73Audit $audit,
        private readonly CampaignSelection731 $selection
    ) {}

    public function preview(array $data): array
    {
        $this->audit->actor(true);

        $this->assertTerms($data);

        $result = $this->selection->collect($data);

        unset($result['variant_ids']);

        return $result;
    }

    public function create(
        string $key,
        array $data,
        string $token
    ): array {
        $actor = $this->audit->actor(true);

        return $this->audit->execute(
            $key,
            'campaign_create',
            [
                ...$data,
                'preview_token' => $token,
            ],
            function () use ($data, $token, $actor): array {
                $this->assertTerms($data);

                $selection = $this->selection->collect($data);

                if (!hash_equals(
                    $selection['preview_token'],
                    $token
                )) {
                    throw new DomainException(
                        'Подбор товаров или цены изменились. Повторите предпросмотр'
                    );
                }

                if ($selection['count'] === 0) {
                    throw new DomainException(
                        'Нет подходящих товаров для акции'
                    );
                }

                $id = (int) DB::table('marketing_campaigns731')
                    ->insertGetId([
                        'price_list_id' => $data['price_list_id'],
                        'created_by' => $actor,
                        'name' => $data['name'],
                        'badge' => $data['badge'],
                        'discount_type' => $data['discount_type'],
                        'discount_value' => $data['discount_value'],
                        'max_discount_minor' =>
                            $data['max_discount_minor'] ?? null,
                        'priority' => $data['priority'],
                        'allow_promo' => $data['allow_promo'],
                        'allow_loyalty' => $data['allow_loyalty'],
                        'is_active' => false,
                        'revision' => 1,
                        'starts_at' => $data['starts_at'],
                        'ends_at' => $data['ends_at'],
                        'selection_json' => json_encode([
                            'scope_type' => $data['scope_type'],
                            'scope_ids' => $data['scope_ids'],
                            'excluded_variant_ids' =>
                                $data['excluded_variant_ids'],
                            'max_stock' => $data['max_stock'] ?? null,
                            'unsold_days' => $data['unsold_days'] ?? null,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                foreach (array_chunk(
                             $selection['variant_ids'],
                             1000
                         ) as $chunk) {
                    DB::table('marketing_campaign_variants731')
                        ->insert(array_map(
                            static fn (int $variantId): array => [
                                'campaign_id' => $id,
                                'variant_id' => $variantId,
                            ],
                            $chunk
                        ));
                }

                return [
                    'campaign_id' => $id,
                    'revision' => 1,
                    'is_active' => false,
                    'variant_count' => $selection['count'],
                    'preview_token' => $token,
                ];
            }
        );
    }

    public function toggle(
        string $key,
        array $data
    ): array {
        return $this->audit->execute(
            $key,
            'campaign_toggle',
            $data,
            function () use ($data): array {
                $row = DB::table('marketing_campaigns731')
                    ->where('id', $data['campaign_id'])
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    abort(404, 'Campaign not found');
                }

                if ((int) $row->revision
                    !== (int) $data['expected_revision']
                    || (int) $row->revision >= 2147483646) {
                    throw new DomainException(
                        'Акция изменилась. Обновите список'
                    );
                }

                $active = (bool) $data['is_active'];

                if ($active) {
                    $databaseNow = CarbonImmutable::parse(
                        DB::selectOne(
                            'SELECT clock_timestamp() AS ts'
                        )->ts
                    );

                    if (CarbonImmutable::parse($row->ends_at)
                        ->lte($databaseNow)) {
                        throw new DomainException(
                            'Срок акции уже истёк'
                        );
                    }

                    if (!DB::table('price_lists as pl')
                        ->join(
                            'currencies as c',
                            'c.code',
                            '=',
                            'pl.currency_code'
                        )
                        ->where('pl.id', $row->price_list_id)
                        ->where('pl.type', 'retail')
                        ->where('pl.is_active', true)
                        ->where('c.is_active', true)
                        ->exists()) {
                        throw new DomainException(
                            'Прайс-лист недоступен'
                        );
                    }
                }

                $revision = (int) $row->revision + 1;

                DB::table('marketing_campaigns731')
                    ->where('id', $row->id)
                    ->update([
                        'is_active' => $active,
                        'revision' => $revision,
                        'updated_at' => now(),
                    ]);

                return [
                    'campaign_id' => (int) $row->id,
                    'is_active' => $active,
                    'revision' => $revision,
                ];
            }
        );
    }

    public function listing(array $filters): array
    {
        $this->audit->actor();

        $query = DB::table('marketing_campaigns731 as c')
            ->join('price_lists as pl', 'pl.id', '=', 'c.price_list_id')
            ->select([
                'c.*',
                'pl.code as price_list_code',
                'pl.currency_code',
            ])
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN c.ends_at <= CURRENT_TIMESTAMP THEN 'expired'
                    WHEN c.is_active = FALSE THEN 'disabled'
                    WHEN c.starts_at > CURRENT_TIMESTAMP THEN 'scheduled'
                    ELSE 'running'
                END AS state
            SQL)
            ->selectRaw(<<<'SQL'
                (
                    SELECT COUNT(*)
                    FROM marketing_campaign_variants731 t
                    WHERE t.campaign_id = c.id
                ) AS variant_count
            SQL);

        if (isset($filters['before_id'])) {
            $query->where('c.id', '<', $filters['before_id']);
        }

        $rows = $query->orderByDesc('c.id')
            ->limit(31)
            ->get();

        $items = $rows->take(30)->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $rows->count() > 30
                ? (int) $items[count($items) - 1]->id
                : null,
        ];
    }

    public function variants(
        int $campaignId,
        array $filters
    ): array {
        $this->audit->actor();

        $campaign = DB::table('marketing_campaigns731')
            ->where('id', $campaignId)
            ->first();

        if ($campaign === null) {
            abort(404);
        }

        $query = DB::table('marketing_campaign_variants731 as t')
            ->join('product_variants as v', 'v.id', '=', 't.variant_id')
            ->leftJoin(
                'effective_variant_prices731 as ep',
                function ($join) use ($campaign): void {
                    $join->on('ep.variant_id', '=', 'v.id')
                        ->where(
                            'ep.price_list_id',
                            '=',
                            $campaign->price_list_id
                        );
                }
            )
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'v.product_id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->where('t.campaign_id', $campaignId)
            ->select([
                'v.id',
                'v.sku',
                'v.is_active',
                'tr.name as product_name',
                'ep.base_price_minor',
                'ep.price_minor',
                'ep.campaign_id as winning_campaign_id',
            ]);

        if (isset($filters['before_id'])) {
            $query->where('v.id', '<', $filters['before_id']);
        }

        $rows = $query->orderByDesc('v.id')
            ->limit(51)
            ->get();

        $items = $rows->take(50)->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $rows->count() > 50
                ? (int) $items[count($items) - 1]->id
                : null,
        ];
    }

    private function assertTerms(array $data): void
    {
        $start = CarbonImmutable::parse($data['starts_at']);
        $end = CarbonImmutable::parse($data['ends_at']);

        $databaseNow = CarbonImmutable::parse(
            DB::selectOne(
                'SELECT clock_timestamp() AS ts'
            )->ts
        );

        if (!$start->lt($end)) {
            throw new DomainException(
                'Окончание акции должно быть позже начала'
            );
        }

        if ($end->lte($databaseNow)) {
            throw new DomainException(
                'Дата окончания акции уже прошла'
            );
        }

        if ($data['discount_type'] === 'percent'
            && (int) $data['discount_value'] > 10000) {
            throw new DomainException(
                'Скидка не может превышать 100%'
            );
        }
    }
}
