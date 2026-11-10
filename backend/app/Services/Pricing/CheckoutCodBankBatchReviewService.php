<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Seal and independently review a bank batch without acknowledging payment. */
final class CheckoutCodBankBatchReviewService
{
    public const REVIEW_ABILITY = 'cod.bank-batch.review';

    public function __construct(
        private readonly CheckoutCodBankBatchPolicy $policy
    ) {
    }

    /** @return array<string, mixed> */
    public function review(
        string $batchKey,
        string $operationKey
    ): array {
        $actor = $this->actor();

        if (!Str::isUuid($batchKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException(
                'Invalid bank batch review UUID'
            );
        }

        $batchKey = strtolower($batchKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor,
            $batchKey,
            $operationKey
        ): array {
            $batch = DB::table('checkout_cod_bank_batches')
                ->where('operation_key', $batchKey)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                throw new DomainException('Bank batch not found');
            }

            $totals = DB::table('checkout_cod_bank_batch_allocations')
                ->where('batch_id', $batch->id)
                ->selectRaw(
                    'COUNT(*) AS count, COALESCE(SUM(amount_minor), 0) AS amount'
                )
                ->first();

            $count = (int) $totals->count;
            $amount = (int) $totals->amount;

            if ($count === 0 || (int) $batch->recorded_by === $actor) {
                throw new DomainException(
                    'Populated batch and independent reviewer required'
                );
            }

            $conflict = DB::table(
                'checkout_cod_bank_batch_allocations as a'
            )
                ->join(
                    'checkout_cod_settlement_observations as o',
                    'o.id',
                    '=',
                    'a.observation_id'
                )
                ->where('a.batch_id', $batch->id)
                ->where(function ($q) use ($actor): void {
                    $q->where('a.allocated_by', $actor)
                        ->orWhere('o.recorded_by', $actor);
                })
                ->exists();

            if ($conflict) {
                throw new DomainException(
                    'Batch reviewer must be independent of source and allocations'
                );
            }

            $decision = $this->policy->evaluate(
                (int) $batch->claimed_net_minor,
                $amount,
                $count
            );

            $signature = $this->hmac([
                'cod-bank-batch-review-v1',
                $batchKey,
                $operationKey,
                (int) $batch->id,
                $actor,
                $decision['decision'],
                $decision['reason_code'],
                $count,
                $amount,
            ]);

            $old = DB::table('checkout_cod_bank_batch_reviews')
                ->where('batch_id', $batch->id)
                ->first();

            if ($old !== null) {
                if (
                    (string) $old->operation_key !== $operationKey
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $signature
                    )
                ) {
                    throw new DomainException('Bank batch already reviewed');
                }

                return $this->present($old);
            }

            if (
                DB::table('checkout_cod_bank_batch_reviews')
                    ->where('operation_key', $operationKey)
                    ->exists()
            ) {
                throw new DomainException('Batch review UUID reused');
            }

            $id = DB::table('checkout_cod_bank_batch_reviews')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'batch_id' => $batch->id,
                    'decision' => $decision['decision'],
                    'reason_code' => $decision['reason_code'],
                    'allocation_count' => $count,
                    'allocated_minor' => $amount,
                    'reviewed_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_bank_batch_reviews')
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
            || !Gate::forUser($user)->allows(self::REVIEW_ABILITY)
        ) {
            throw new AuthorizationException(
                'Bank batch review permission denied'
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
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'allocation_count' => (int) $row->allocation_count,
            'allocated_minor' => (int) $row->allocated_minor,
            'reviewed_by' => (int) $row->reviewed_by,
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
