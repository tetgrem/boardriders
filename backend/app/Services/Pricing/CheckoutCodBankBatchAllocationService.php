<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only partial allocation of one bank batch to a reviewed NovaPay claim. */
final class CheckoutCodBankBatchAllocationService
{
    public const ALLOCATE_ABILITY = 'cod.bank-batch.allocate';

    /** @return array<string, mixed> */
    public function allocate(
        string $batchKey,
        string $observationKey,
        string $operationKey,
        int $amountMinor
    ): array {
        $actor = $this->actor();

        if (
            !Str::isUuid($batchKey)
            || !Str::isUuid($observationKey)
            || !Str::isUuid($operationKey)
            || $amountMinor < 1
            || $amountMinor > 100000000
        ) {
            throw new InvalidArgumentException(
                'Invalid COD bank batch allocation'
            );
        }

        $batchKey = strtolower($batchKey);
        $observationKey = strtolower($observationKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor,
            $batchKey,
            $observationKey,
            $operationKey,
            $amountMinor
        ): array {
            $batch = DB::table('checkout_cod_bank_batches')
                ->where('operation_key', $batchKey)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                throw new DomainException('Bank batch not found');
            }

            $claim = DB::table('checkout_cod_settlement_observations')
                ->where('operation_key', $observationKey)
                ->first();

            if ($claim === null) {
                throw new DomainException('NovaPay claim not found');
            }

            // Lock the same case row as the Block 24 single-match path.
            $case = DB::table('checkout_cod_settlement_cases')
                ->where('id', $claim->case_id)
                ->lockForUpdate()
                ->first(['id']);

            $review = DB::table('checkout_cod_settlement_reviews')
                ->where('observation_id', $claim->id)
                ->first(['decision']);

            if (
                $case === null
                || $claim->source_kind !== 'novapay_statement'
                || $claim->comparison_result !== 'consistent_with_estimate'
                || $review?->decision !== 'retain_unverified'
                || (int) $batch->recorded_by === $actor
                || (int) $claim->recorded_by === $actor
            ) {
                throw new DomainException(
                    'Independent, reviewed NovaPay source required'
                );
            }

            $signature = $this->hmac([
                'cod-bank-batch-allocation-v1',
                $batchKey,
                $observationKey,
                $operationKey,
                (int) $batch->id,
                (int) $claim->id,
                (int) $case->id,
                $amountMinor,
                $actor,
            ]);

            $old = DB::table('checkout_cod_bank_batch_allocations')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                if (
                    (int) $old->batch_id !== (int) $batch->id
                    || (int) $old->observation_id !== (int) $claim->id
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $signature
                    )
                ) {
                    throw new DomainException(
                        'Bank batch allocation UUID reused'
                    );
                }

                return $this->present($old);
            }

            if (
                DB::table('checkout_cod_bank_batch_reviews')
                    ->where('batch_id', $batch->id)
                    ->exists()
            ) {
                throw new DomainException('Reviewed bank batch is sealed');
            }

            if (
                DB::table('checkout_cod_settlement_bank_matches')
                    ->where('observation_id', $claim->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'NovaPay claim already matched to a single receipt'
                );
            }

            if (
                DB::table('checkout_cod_bank_batch_allocations')
                    ->where('batch_id', $batch->id)
                    ->where('observation_id', $claim->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'NovaPay claim already allocated within this batch'
                );
            }

            $batchAllocated = (int) DB::table(
                'checkout_cod_bank_batch_allocations'
            )
                ->where('batch_id', $batch->id)
                ->sum('amount_minor');

            $claimAllocated = (int) DB::table(
                'checkout_cod_bank_batch_allocations'
            )
                ->where('observation_id', $claim->id)
                ->sum('amount_minor');

            if (
                $batchAllocated + $amountMinor > (int) $batch->claimed_net_minor
                || $claimAllocated + $amountMinor > (int) $claim->claimed_net_minor
            ) {
                throw new DomainException(
                    'Bank batch or NovaPay claim would be overallocated'
                );
            }

            $id = DB::table('checkout_cod_bank_batch_allocations')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'batch_id' => $batch->id,
                    'case_id' => $case->id,
                    'observation_id' => $claim->id,
                    'amount_minor' => $amountMinor,
                    'allocated_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_bank_batch_allocations')
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
            || !Gate::forUser($user)->allows(self::ALLOCATE_ABILITY)
        ) {
            throw new AuthorizationException(
                'Bank batch allocation permission denied'
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
            'batch_id' => (int) $row->batch_id,
            'case_id' => (int) $row->case_id,
            'observation_id' => (int) $row->observation_id,
            'amount_minor' => (int) $row->amount_minor,
            'allocated_by' => (int) $row->allocated_by,
            'external_source_authenticated' => false,
            'cod_paid' => false,
        ];
    }
}
