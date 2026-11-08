<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NovaPoshtaDirectoryService
{
    public function __construct(private readonly NovaPoshtaApiClient $client)
    {
    }

    /**
     * Backward-compatible endpoint name: returns delivery CityRef, not SettlementRef.
     *
     * @return array<int, array<string, mixed>>
     */
    public function settlements(string $query, int $limit = 20): array
    {
        if (preg_match('/[\x00-\x1F\x7F]/u', $query)) {
            throw new InvalidArgumentException('Invalid city search');
        }

        $query = trim($query);

        if (
            mb_strlen($query) < 2
            || mb_strlen($query) > 100
            || $limit < 1
            || $limit > 50
        ) {
            throw new InvalidArgumentException('Invalid city search');
        }

        $key = 'np:delivery-cities:v2:' . hash(
                'sha256',
                mb_strtolower($query) . ':' . $limit
            );

        return Cache::remember($key, now()->addHours(6), function () use ($query, $limit) {
            $data = $this->client->call('Address', 'getCities', [
                'FindByString' => $query,
                'Limit' => $limit,
                'Page' => 1,
            ]);

            $result = [];

            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $ref = $this->uuid($item['Ref'] ?? null);
                $name = $this->safeText($item['Description'] ?? null);

                if ($ref === null || $name === null || isset($result[$ref])) {
                    continue;
                }

                $result[$ref] = [
                    'ref' => $ref,
                    'name' => $name,
                    'region' => $this->safeText($item['AreaDescription'] ?? null),
                    'ref_type' => 'delivery_city',
                ];

                if (count($result) >= $limit) {
                    break;
                }
            }

            return array_values($result);
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function warehouses(
        string $cityRef,
        string $type = 'all',
        int $page = 1,
        int $limit = 50,
    ): array {
        $cityRef = $this->uuid($cityRef)
            ?? throw new InvalidArgumentException('Invalid city UUID');

        if (
            !in_array($type, ['all', 'branch', 'parcel_locker'], true)
            || $page < 1
            || $page > 1000
            || $limit < 1
            || $limit > 100
        ) {
            throw new InvalidArgumentException('Invalid warehouse filters');
        }

        $key = "np:warehouses:v2:{$cityRef}:{$page}:{$limit}";

        $all = Cache::remember($key, now()->addMinutes(30), function () use (
            $cityRef, $page, $limit
        ) {
            $data = $this->client->call('AddressGeneral', 'getWarehouses', [
                'CityRef' => $cityRef,
                'Page' => $page,
                'Limit' => $limit,
            ]);

            $result = [];

            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $ref = $this->uuid($item['Ref'] ?? null);
                $name = $this->safeText($item['Description'] ?? null);
                $kind = match ($item['CategoryOfWarehouse'] ?? null) {
                    'Branch' => 'branch',
                    'Postomat' => 'parcel_locker',
                    default => null,
                };

                if (
                    $ref === null
                    || $name === null
                    || $kind === null
                    || isset($result[$ref])
                ) {
                    continue;
                }

                $result[$ref] = [
                    'ref' => $ref,
                    'name' => $name,
                    'type' => $kind,
                    'number' => $this->safeText($item['Number'] ?? null, 32),
                    'city_ref' => $cityRef,
                    'directory_match' => true,
                    'cod_eligibility_confirmed' => false,
                ];
            }

            return array_values($result);
        });

        if ($type === 'all') {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (array $warehouse): bool => $warehouse['type'] === $type
        ));
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value)
            ? strtolower($value)
            : null;
    }

    private function safeText(mixed $value, int $max = 255): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
        && mb_strlen($value) <= $max
        && !preg_match('/[\x00-\x1F\x7F]/u', $value)
            ? $value
            : null;
    }
}
