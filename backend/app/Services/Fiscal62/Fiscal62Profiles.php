<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Append-only, explicitly approved Checkbox item codes
 * and tax groups.
 */
final class Fiscal62Profiles
{
    public function __construct(
        private readonly Fiscal62Access $access,
        private readonly CheckoutOrderReturnSigner $signer,
    ) {}

    public function approve(
        int $variantId,
        string $code,
        string $name,
        int $taxCode
    ): array {
        $actor = $this->access->actor(
            Fiscal62Access::PROFILE
        );

        $code = trim($code);
        $name = trim($name);

        if (
            $variantId <= 0
            || !preg_match(
                '/^[A-Za-z0-9_.-]{1,40}$/D',
                $code
            )
            || mb_strlen($name) < 3
            || mb_strlen($name) > 200
            || $taxCode < 1
            || $taxCode > 20
        ) {
            throw new DomainException(
                'Invalid approved fiscal product details'
            );
        }

        return DB::transaction(function () use (
            $variantId,
            $code,
            $name,
            $taxCode,
            $actor
        ): array {
            $variant = DB::table('product_variants')
                ->where('id', $variantId)
                ->lockForUpdate()
                ->first(['id']);

            if ($variant === null) {
                throw new DomainException('Unknown variant');
            }

            $existing = DB::table(
                'checkout_fiscal_profiles62'
            )
                ->where('variant_id', $variantId)
                ->first();

            if ($existing !== null) {
                $saved = $this->read($variantId);

                if (
                    $saved['fiscal_code'] !== $code
                    || $saved['fiscal_name'] !== $name
                    || $saved['tax_code'] !== $taxCode
                ) {
                    throw new DomainException(
                        'Fiscal tax profile already approved; '
                        . 'change requires separate revision'
                    );
                }

                return $saved;
            }

            $identity = [
                'variant_id' => $variantId,
                'fiscal_code' => $code,
                'fiscal_name' => $name,
                'tax_code' => $taxCode,
                'approved_by' => $actor,
            ];

            DB::table('checkout_fiscal_profiles62')->insert([
                ...$identity,
                'profile_hmac' =>
                    $this->signer->digest($identity),
                'created_at' => now(),
            ]);

            return $this->read($variantId);
        }, 3);
    }

    public function read(int $variantId): array
    {
        $row = DB::table('checkout_fiscal_profiles62')
            ->where('variant_id', $variantId)
            ->first();

        if ($row === null) {
            throw new DomainException(
                'Fiscal profile not approved for variant '
                . $variantId
            );
        }

        $identity = [
            'variant_id' => (int) $row->variant_id,
            'fiscal_code' => (string) $row->fiscal_code,
            'fiscal_name' => (string) $row->fiscal_name,
            'tax_code' => (int) $row->tax_code,
            'approved_by' => (int) $row->approved_by,
        ];

        if (!hash_equals(
            (string) $row->profile_hmac,
            $this->signer->digest($identity)
        )) {
            throw new RuntimeException(
                'Approved fiscal tax profile HMAC invalid'
            );
        }

        return $identity;
    }
}
