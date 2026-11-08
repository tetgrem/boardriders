<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class NovaPoshtaDestinationPreflightService
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 20;

    public function __construct(private readonly NovaPoshtaApiClient $client)
    {
    }

    /**
     * Fresh provider lookup. Does not persist approval, modify an order,
     * confirm COD eligibility, or authorize dispatch.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $cityRef, string $warehouseRef, string $type): array
    {
        if (!Str::isUuid($cityRef) || !Str::isUuid($warehouseRef)) {
            throw new InvalidArgumentException('Invalid destination UUID');
        }

        if (!in_array($type, ['branch', 'parcel_locker'], true)) {
            throw new InvalidArgumentException('Invalid destination type');
        }

        $cityRef = strtolower($cityRef);
        $warehouseRef = strtolower($warehouseRef);

        $result = [
            'city_ref' => $cityRef,
            'warehouse_ref' => $warehouseRef,
            'requested_type' => $type,
            'directory_match' => false,
            'type_match' => false,
            'operational' => false,
            'destination_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
            'warehouse_name' => null,
        ];

        // Query with CityRef and inspect actual returned CityRef and Ref.
        // Do not rely on an undocumented direct warehouse-Ref filter.
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $rows = $this->client->call('AddressGeneral', 'getWarehouses', [
                'CityRef' => $cityRef,
                'Page' => $page,
                'Limit' => self::PAGE_SIZE,
            ]);

            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['Ref'] ?? null)) {
                    continue;
                }

                if (strtolower($row['Ref']) !== $warehouseRef) {
                    continue;
                }

                // Both refs must be confirmed by the provider response.
                if (
                    !is_string($row['CityRef'] ?? null)
                    || strtolower($row['CityRef']) !== $cityRef
                ) {
                    return $result;
                }

                $category = $row['CategoryOfWarehouse'] ?? null;
                $foundType = match ($category) {
                    'Branch' => 'branch',
                    'Postomat' => 'parcel_locker',
                    default => null,
                };

                $name = $row['Description'] ?? null;

                if (
                    $foundType === null
                    || !is_string($name)
                    || trim($name) === ''
                    || mb_strlen(trim($name)) > 255
                ) {
                    return $result;
                }

                $isOperational = ($row['WarehouseStatus'] ?? null) === 'Working';
                $typeMatch = $foundType === $type;

                return array_replace($result, [
                    'directory_match' => true,
                    'type_match' => $typeMatch,
                    'operational' => $isOperational,
                    'destination_verified' => $typeMatch && $isOperational,
                    'warehouse_name' => trim($name),
                ]);
            }

            if (count($rows) < self::PAGE_SIZE) {
                return $result;
            }
        }

        // The scan limit was reached. Do not guess whether destination exists.
        return $result;
    }
}
