<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Register a bank batch CLAIM, not an authenticated deposit. */
final class CheckoutCodBankBatchService
{
    public const RECORD_ABILITY = 'cod.bank-batch.record';

    /** @return array<string, mixed> */
    public function record(
        string $operationKey,
        string $reference,
        int $netMinor
    ): array {
        $actor = $this->actor();

        if (
            !Str::isUuid($operationKey)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D', $reference)
            || $netMinor < 1
            || $netMinor > 100000000
        ) {
            throw new InvalidArgumentException('Invalid COD bank batch claim');
        }

        $operationKey = strtolower($operationKey);

        // Same reference fingerprint as the Block 24 bank receipt service.
        $referenceHmac = $this->hmac([
            'cod-bank-reference-v1',
            $reference,
        ]);

        $requestHmac = $this->hmac([
            'cod-bank-batch-v1',
            $operationKey,
            $referenceHmac,
            $netMinor,
            $actor,
        ]);

        return DB::transaction(function () use (
            $operationKey,
            $referenceHmac,
            $requestHmac,
            $netMinor,
            $actor
        ): array {
            $old = DB::table('checkout_cod_bank_batches')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                if (
                    !hash_equals((string) $old->request_hmac, $requestHmac)
                    || (int) $old->recorded_by !== $actor
                ) {
                    throw new DomainException('Bank batch operation UUID reused');
                }

                return $this->present($old);
            }

            if (
                DB::table('checkout_cod_bank_reference_claims')
                    ->where('reference_hmac', $referenceHmac)
                    ->exists()
            ) {
                throw new DomainException('Bank reference belongs to another claim');
            }

            $id = DB::table('checkout_cod_bank_batches')->insertGetId([
                'operation_key' => $operationKey,
                'reference_hmac' => $referenceHmac,
                'claimed_net_minor' => $netMinor,
                'currency_code' => 'UAH',
                'recorded_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);

            // AFTER INSERT trigger atomically claims the reference globally.
            return $this->present(
                DB::table('checkout_cod_bank_batches')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(self::RECORD_ABILITY)
        ) {
            throw new AuthorizationException(
                'Bank batch record permission denied'
            );
        }

        return (int) $id;
    }

    private function hmac(array $payload): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'claimed_net_minor' => (int) $row->claimed_net_minor,
            'currency_code' => (string) $row->currency_code,
            'recorded_by' => (int) $row->recorded_by,
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
