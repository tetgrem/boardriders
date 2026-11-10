<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** One-to-one proposal relating a reviewed NovaPay claim to one bank claim. */
final class CheckoutCodSettlementBankMatchService
{
    public const PROPOSE_ABILITY = 'cod.settlement.bank-match.propose';

    public function __construct(private readonly CheckoutCodBankSettlementPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function propose(
        string $novaPayObservationKey,
        string $bankReceiptKey,
        string $operationKey
    ): array {
        $actor = $this->actor();
        if (!Str::isUuid($novaPayObservationKey)
            || !Str::isUuid($bankReceiptKey)
            || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD bank match UUID');
        }
        $novaPayObservationKey = strtolower($novaPayObservationKey);
        $bankReceiptKey = strtolower($bankReceiptKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor, $novaPayObservationKey, $bankReceiptKey, $operationKey
        ): array {
            $observation = DB::table('checkout_cod_settlement_observations')
                ->where('operation_key', $novaPayObservationKey)->first();
            if ($observation === null) {
                throw new DomainException('NovaPay observation missing');
            }
            $case = DB::table('checkout_cod_settlement_cases')
                ->where('id', $observation->case_id)->lockForUpdate()->first(['id']);
            $bank = DB::table('checkout_cod_settlement_bank_receipts')
                ->where('operation_key', $bankReceiptKey)->first();
            $review = DB::table('checkout_cod_settlement_reviews')
                ->where('observation_id', $observation->id)->first(['id']);

            if ($case === null || $bank === null || $review === null
                || (int) $observation->case_id !== (int) $bank->case_id
                || $observation->source_kind !== 'novapay_statement'
                || $observation->currency_code !== 'UAH'
                || $bank->currency_code !== 'UAH'
                || (int) $observation->recorded_by === $actor
                || (int) $bank->recorded_by === $actor) {
                throw new DomainException('Independent reviewed NovaPay and bank claims required');
            }

            $comparison = $this->policy->compare(
                (int) $observation->claimed_net_minor,
                (int) $bank->claimed_net_minor,
                (string) $observation->comparison_result,
                'UAH'
            );
            $signature = $this->hmac([
                'cod-bank-match-v1', $novaPayObservationKey, $bankReceiptKey,
                $operationKey, $actor, (int) $case->id, (int) $review->id,
                $comparison['match_result'], $comparison['difference_minor'],
            ]);
            $old = DB::table('checkout_cod_settlement_bank_matches')
                ->where('operation_key', $operationKey)->first();
            if ($old !== null) {
                if ((int) $old->case_id !== (int) $case->id
                    || (int) $old->observation_id !== (int) $observation->id
                    || (int) $old->bank_receipt_id !== (int) $bank->id
                    || !hash_equals((string) $old->request_hmac, $signature)) {
                    throw new DomainException('Bank match UUID reused');
                }
                return $this->present($old);
            }

            if (DB::table('checkout_cod_settlement_bank_matches')
                    ->where('observation_id', $observation->id)->exists()
                || DB::table('checkout_cod_settlement_bank_matches')
                    ->where('bank_receipt_id', $bank->id)->exists()) {
                throw new DomainException('NovaPay or bank claim already used in a match');
            }

            $id = DB::table('checkout_cod_settlement_bank_matches')->insertGetId([
                'operation_key' => $operationKey,
                'case_id' => $case->id,
                'observation_id' => $observation->id,
                'bank_receipt_id' => $bank->id,
                'novapay_net_minor' => $comparison['novapay_net_minor'],
                'bank_net_minor' => $comparison['bank_net_minor'],
                'difference_minor' => $comparison['difference_minor'],
                'match_result' => $comparison['match_result'],
                'proposed_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);
            return $this->present(
                DB::table('checkout_cod_settlement_bank_matches')->where('id', $id)->first()
            );
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::PROPOSE_ABILITY)) {
            throw new AuthorizationException('COD bank match permission denied');
        }
        return (int) $id;
    }

    private function hmac(array $data): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $key);
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'case_id' => (int) $row->case_id,
            'observation_id' => (int) $row->observation_id,
            'bank_receipt_id' => (int) $row->bank_receipt_id,
            'novapay_net_minor' => (int) $row->novapay_net_minor,
            'bank_net_minor' => (int) $row->bank_net_minor,
            'difference_minor' => (int) $row->difference_minor,
            'match_result' => (string) $row->match_result,
            'proposed_by' => (int) $row->proposed_by,
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
