<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Operator-entered external financial CLAIM.
 *
 * No import is presumed authentic, no COD is marked paid.
 * Reference IDs are HMACed, not stored in cleartext.
 */
final class CheckoutCodSettlementObservationService
{
    public const RECORD_ABILITY = 'cod.settlement.record-observation';

    public function __construct(
        private readonly CheckoutCodSettlementComparisonPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function record(
        string $caseKey,
        string $operationKey,
        string $sourceKind,
        string $externalReference,
        int $grossMinor,
        int $feeMinor,
        int $netMinor
    ): array {
        $actor = $this->actor();

        if (!Str::isUuid($caseKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException(
                'Invalid settlement observation UUID'
            );
        }

        if (!in_array(
            $sourceKind,
            CheckoutCodSettlementComparisonPolicy::SOURCES,
            true
        )) {
            throw new InvalidArgumentException(
                'Unsupported settlement source kind'
            );
        }

        // This is a technical reference, never a name, card or phone number.
        if (!preg_match(
            '/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D',
            $externalReference
        )) {
            throw new InvalidArgumentException(
                'Invalid technical external reference'
            );
        }

        $caseKey = strtolower($caseKey);
        $operationKey = strtolower($operationKey);

        $referenceHmac = hash_hmac(
            'sha256',
            $sourceKind . ':' . $externalReference,
            $this->secret()
        );

        return DB::transaction(function () use (
            $caseKey,
            $operationKey,
            $actor,
            $sourceKind,
            $referenceHmac,
            $grossMinor,
            $feeMinor,
            $netMinor
        ): array {
            $case = DB::table('checkout_cod_settlement_cases')
                ->where('case_key', $caseKey)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                throw new DomainException('COD settlement case not found');
            }

            $comparison = $this->policy->compare(
                (int) $case->expected_cod_minor,
                $case->expected_fee_minor === null
                    ? null
                    : (int) $case->expected_fee_minor,
                $grossMinor,
                $feeMinor,
                $netMinor,
                (string) $case->currency_code
            );

            $hmac = $this->digest([
                'cod-settlement-observation-v1',
                $caseKey,
                $operationKey,
                $actor,
                $sourceKind,
                $referenceHmac,
                $grossMinor,
                $feeMinor,
                $netMinor,
                $comparison['comparison_result'],
            ]);

            $existing = DB::table('checkout_cod_settlement_observations')
                ->where('operation_key', $operationKey)
                ->first();

            if ($existing !== null) {
                if (
                    (int) $existing->case_id !== (int) $case->id
                    || (int) $existing->recorded_by !== $actor
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $hmac
                    )
                ) {
                    throw new DomainException(
                        'Settlement observation UUID reused'
                    );
                }

                return $this->present($existing);
            }

            if (
                DB::table('checkout_cod_settlement_observations')
                    ->where('case_id', $case->id)
                    ->where('external_reference_hmac', $referenceHmac)
                    ->exists()
            ) {
                throw new DomainException(
                    'External settlement reference already recorded'
                );
            }

            $id = DB::table('checkout_cod_settlement_observations')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'case_id' => $case->id,
                    'external_reference_hmac' => $referenceHmac,
                    'source_kind' => $sourceKind,
                    'claimed_gross_minor' => $grossMinor,
                    'claimed_fee_minor' => $feeMinor,
                    'claimed_net_minor' => $netMinor,
                    'currency_code' => $case->currency_code,
                    'comparison_result' =>
                        $comparison['comparison_result'],
                    'recorded_by' => $actor,
                    'request_hmac' => $hmac,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_settlement_observations')
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
                'Settlement observation permission denied'
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

    private function digest(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $this->secret()
        );
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'case_id' => (int) $row->case_id,
            'source_kind' => (string) $row->source_kind,
            'claimed_gross_minor' =>
                (int) $row->claimed_gross_minor,
            'claimed_fee_minor' =>
                (int) $row->claimed_fee_minor,
            'claimed_net_minor' =>
                (int) $row->claimed_net_minor,
            'currency_code' => (string) $row->currency_code,
            'comparison_result' =>
                (string) $row->comparison_result,
            'recorded_by' => (int) $row->recorded_by,
            'source_authenticity_verified' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
            'automatic_settlement_allowed' => false,
        ];
    }
}
