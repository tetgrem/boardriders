<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProductVariantShippingMetricsService
{
    /**
     * Internal catalog/PIM operation. All dimensions are millimeters.
     * Existing weight_grams is reused; no new weight column is introduced.
     *
     * @return array<string, mixed>
     */
    public function change(
        int $variantId,
        string $changeKey,
        int $expectedRevision,
        ?int $weightGrams,
        ?int $lengthMm,
        ?int $widthMm,
        ?int $heightMm,
    ): array {
        if ($variantId <= 0 || !Str::isUuid($changeKey) || $expectedRevision < 0) {
            throw new InvalidArgumentException('Invalid shipping measurement operation');
        }

        $dims = [$lengthMm, $widthMm, $heightMm];
        $allNull = $lengthMm === null && $widthMm === null && $heightMm === null;
        $allValid = count(array_filter(
                $dims,
                static fn (mixed $v): bool => is_int($v) && $v >= 1 && $v <= 100000
            )) === 3;

        if (
            ($weightGrams !== null && ($weightGrams < 1 || $weightGrams > 10000000))
            || (!$allNull && !$allValid)
            || ($allValid && $weightGrams === null)
        ) {
            throw new InvalidArgumentException('Invalid weight or dimensions');
        }

        $changeKey = strtolower($changeKey);
        $requestSha = hash('sha256', json_encode([
            $variantId, $expectedRevision, $weightGrams,
            $lengthMm, $widthMm, $heightMm,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $variantId, $changeKey, $expectedRevision, $weightGrams,
            $lengthMm, $widthMm, $heightMm, $requestSha
        ): array {
            $variant = DB::table('product_variants')
                ->where('id', $variantId)
                ->lockForUpdate()
                ->first([
                    'id', 'weight_grams', 'shipping_length_mm',
                    'shipping_width_mm', 'shipping_height_mm',
                    'shipping_measurement_revision',
                ]);

            if ($variant === null) {
                throw new DomainException('Product variant not found');
            }

            $existing = DB::table('product_variant_shipping_measurement_changes')
                ->where('change_key', $changeKey)->first();

            if ($existing !== null) {
                if (
                    (int) $existing->variant_id !== $variantId
                    || !hash_equals((string) $existing->request_sha256, $requestSha)
                ) {
                    throw new DomainException('Shipping change key reused');
                }

                return $this->present($existing);
            }

            if ((int) $variant->shipping_measurement_revision !== $expectedRevision) {
                throw new DomainException('Shipping measurement revision is stale');
            }

            $previous = [
                'weight_grams' => $variant->weight_grams === null ? null : (int) $variant->weight_grams,
                'length_mm' => $variant->shipping_length_mm === null ? null : (int) $variant->shipping_length_mm,
                'width_mm' => $variant->shipping_width_mm === null ? null : (int) $variant->shipping_width_mm,
                'height_mm' => $variant->shipping_height_mm === null ? null : (int) $variant->shipping_height_mm,
            ];
            $current = [
                'weight_grams' => $weightGrams,
                'length_mm' => $lengthMm,
                'width_mm' => $widthMm,
                'height_mm' => $heightMm,
            ];

            $id = DB::table('product_variant_shipping_measurement_changes')->insertGetId([
                'change_key' => $changeKey,
                'variant_id' => $variantId,
                'old_revision' => $expectedRevision,
                'new_revision' => $expectedRevision + 1,
                'previous_json' => json_encode($previous, JSON_THROW_ON_ERROR),
                'current_json' => json_encode($current, JSON_THROW_ON_ERROR),
                'request_sha256' => $requestSha,
                'created_at' => now(),
            ]);

            DB::table('product_variants')->where('id', $variantId)->update([
                'weight_grams' => $weightGrams,
                'shipping_length_mm' => $lengthMm,
                'shipping_width_mm' => $widthMm,
                'shipping_height_mm' => $heightMm,
                'shipping_measurement_revision' => $expectedRevision + 1,
                'updated_at' => now(),
            ]);

            return $this->present(
                DB::table('product_variant_shipping_measurement_changes')
                    ->where('id', $id)->first()
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'change_key' => (string) $row->change_key,
            'variant_id' => (int) $row->variant_id,
            'old_revision' => (int) $row->old_revision,
            'new_revision' => (int) $row->new_revision,
            'previous' => json_decode($row->previous_json, true, 512, JSON_THROW_ON_ERROR),
            'current' => json_decode($row->current_json, true, 512, JSON_THROW_ON_ERROR),
            'created_at' => (string) $row->created_at,
        ];
    }
}
