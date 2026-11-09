<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Independent financial review with immutable decision history. */
final class CheckoutCodSettlementReviewService
{
    public const REVIEW_ABILITY = 'cod.settlement.review';

    public function __construct(
        private readonly CheckoutCodSettlementComparisonPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function review(
        string $observationKey,
        string $operationKey,
        string $decision,
        string $reason
    ): array {
        $actor = $this->actor();

        if (
            !Str::isUuid($observationKey)
            || !Str::isUuid($operationKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid settlement review UUID'
            );
        }

        $observationKey = strtolower($observationKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor,
            $observationKey,
            $operationKey,
            $decision,
            $reason
        ): array {
            $observation = DB::table('checkout_cod_settlement_observations')
                ->where('operation_key', $observationKey)
                ->lockForUpdate()
                ->first();

            if ($observation === null) {
                throw new DomainException(
                    'Settlement observation not found'
                );
            }

            if ((int) $observation->recorded_by === $actor) {
                throw new DomainException(
                    'Financial observation requires independent reviewer'
                );
            }

            $this->policy->reviewDecision(
                (string) $observation->comparison_result,
                $decision,
                $reason
            );

            $hmac = hash_hmac(
                'sha256',
                json_encode([
                    'cod-settlement-review-v1',
                    $observationKey,
                    $operationKey,
                    $actor,
                    $decision,
                    $reason,
                    (string) $observation->request_hmac,
                ], JSON_THROW_ON_ERROR),
                $this->secret()
            );

            $existing = DB::table('checkout_cod_settlement_reviews')
                ->where('observation_id', $observation->id)
                ->first();

            if ($existing !== null) {
                if (
                    (string) $existing->operation_key
                    !== $operationKey
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $hmac
                    )
                ) {
                    throw new DomainException(
                        'Settlement observation already reviewed'
                    );
                }

                return $this->present($existing);
            }

            if (
                DB::table('checkout_cod_settlement_reviews')
                    ->where('operation_key', $operationKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Settlement review UUID already used'
                );
            }

            $id = DB::table('checkout_cod_settlement_reviews')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'observation_id' => $observation->id,
                    'decision' => $decision,
                    'reason_code' => $reason,
                    'reviewed_by' => $actor,
                    'request_hmac' => $hmac,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_settlement_reviews')
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
                'Settlement review permission denied'
            );
        }

        return (int) $id;
    }

    private function secret(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return $key;
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'observation_id' => (int) $row->observation_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'external_confirmation_required' => true,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
