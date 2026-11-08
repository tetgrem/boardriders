<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Conservative local screening, not the carrier's shipping or COD approval.
 * Dimensions describe the OUTSIDE of the packed parcel, in millimetres.
 */
final class NovaPoshtaPackageGeometryPolicy
{
    public const RULESET = 'boardriders-ua-standard-parcel-v1';

    /**
     * @param array<int, array<string, mixed>> $items Manifest item snapshots.
     * @return array<string, mixed>
     */
    public function evaluate(
        int $estimatedGrossGrams,
        int $lengthMm,
        int $widthMm,
        int $heightMm,
        string $deliveryType,
        array $items,
    ): array {
        if ($estimatedGrossGrams < 1 || $estimatedGrossGrams > 10000000) {
            throw new InvalidArgumentException('Invalid estimated parcel weight');
        }

        foreach ([$lengthMm, $widthMm, $heightMm] as $side) {
            if ($side < 1 || $side > 100000) {
                throw new InvalidArgumentException('Invalid outer parcel dimensions');
            }
        }

        if (!in_array($deliveryType, ['branch', 'parcel_locker'], true)) {
            throw new InvalidArgumentException('Unsupported destination type');
        }

        if ($items === [] || count($items) > 200) {
            throw new InvalidArgumentException('Missing parcel contents');
        }

        $sides = [$lengthMm, $widthMm, $heightMm];
        rsort($sides, SORT_NUMERIC);

        // NP formula: volume weight (kg) = cm^3 / 4000.
        // In integer millimetres: grams = ceil(mm^3 / 4000).
        $volumeMm3 = $lengthMm * $widthMm * $heightMm;
        $volumeWeightGrams = intdiv($volumeMm3 + 3999, 4000);
        $chargeableGrams = max($estimatedGrossGrams, $volumeWeightGrams);

        $reasons = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid parcel item');
            }

            $itemSides = [
                $item['length_mm'] ?? null,
                $item['width_mm'] ?? null,
                $item['height_mm'] ?? null,
            ];

            foreach ($itemSides as $side) {
                if (!is_int($side) || $side < 1 || $side > 100000) {
                    throw new InvalidArgumentException(
                        'Invalid parcel item measurements'
                    );
                }
            }

            rsort($itemSides, SORT_NUMERIC);

            if (
                $itemSides[0] > $sides[0]
                || $itemSides[1] > $sides[1]
                || $itemSides[2] > $sides[2]
            ) {
                $reasons[] = 'sku_cannot_fit_outer_dimensions';
                break;
            }
        }

        if ($deliveryType === 'parcel_locker') {
            if ($chargeableGrams > 20000) {
                $reasons[] = 'parcel_locker_weight_limit';
            }

            if (
                $sides[0] > 600
                || $sides[1] > 400
                || $sides[2] > 300
            ) {
                $reasons[] = 'parcel_locker_size_limit';
            }

            $result = $reasons === []
                ? 'within_standard_limits'
                : 'blocked';
        } else {
            if ($chargeableGrams > 30000) {
                $reasons[] = 'standard_branch_weight_limit';
            }

            if (
                $sides[0] > 1200
                || $sides[1] > 700
                || $sides[2] > 700
            ) {
                $reasons[] = 'standard_branch_size_limit';
            }

            // A cargo-capable branch might accept a larger parcel.
            $result = $reasons === []
                ? 'within_standard_limits'
                : 'manual_review';
        }

        return [
            'ruleset' => self::RULESET,
            'destination_type' => $deliveryType,
            'length_mm' => $lengthMm,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'estimated_gross_grams' => $estimatedGrossGrams,
            'volume_weight_grams' => $volumeWeightGrams,
            'chargeable_weight_grams' => $chargeableGrams,
            'local_result' => $result,
            'reasons' => array_values(array_unique($reasons)),
            'item_fit_check_only' => true,
            'declared_value_checked' => false,
            'carrier_acceptance_confirmed' => false,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
