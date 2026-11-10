<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Fourth-eyes financial comparison review; not a settlement approval. */
final class CheckoutCodSettlementBankReviewService
{
    public const REVIEW_ABILITY = 'cod.settlement.bank-match.review';

    public function __construct(private readonly CheckoutCodBankSettlementPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function review(
        string $matchKey,
        string $operationKey,
        string $decision,
        string $reason
    ): array {
        $actor = $this->actor();
        if (!Str::isUuid($matchKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD bank review UUID');
        }
        $matchKey = strtolower($matchKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor, $matchKey, $operationKey, $decision, $reason
        ): array {
            $match = DB::table('checkout_cod_settlement_bank_matches')
                ->where('operation_key', $matchKey)->lockForUpdate()->first();
            if ($match === null) {
                throw new DomainException('Bank match not found');
            }
            $bank = DB::table('checkout_cod_settlement_bank_receipts')
                ->where('id', $match->bank_receipt_id)->first(['recorded_by']);
            $novaPay = DB::table('checkout_cod_settlement_observations')
                ->where('id', $match->observation_id)->first(['recorded_by']);

            if ($bank === null || $novaPay === null
                || (int) $match->proposed_by === $actor
                || (int) $bank->recorded_by === $actor
                || (int) $novaPay->recorded_by === $actor) {
                throw new DomainException('Independent bank match reviewer required');
            }
            $this->policy->assertReview(
                (string) $match->match_result, $decision, $reason
            );

            $signature = $this->hmac([
                'cod-bank-match-review-v1', $matchKey, $operationKey,
                $actor, $decision, $reason, (string) $match->request_hmac,
            ]);
            $old = DB::table('checkout_cod_settlement_bank_reviews')
                ->where('match_id', $match->id)->first();
            if ($old !== null) {
                if ((string) $old->operation_key !== $operationKey
                    || !hash_equals((string) $old->request_hmac, $signature)) {
                    throw new DomainException('Bank match already reviewed');
                }
                return $this->present($old);
            }
            if (DB::table('checkout_cod_settlement_bank_reviews')
                ->where('operation_key', $operationKey)->exists()) {
                throw new DomainException('Bank match review UUID reused');
            }

            $id = DB::table('checkout_cod_settlement_bank_reviews')->insertGetId([
                'operation_key' => $operationKey,
                'match_id' => $match->id,
                'decision' => $decision,
                'reason_code' => $reason,
                'reviewed_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);
            return $this->present(
                DB::table('checkout_cod_settlement_bank_reviews')->where('id', $id)->first()
            );
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::REVIEW_ABILITY)) {
            throw new AuthorizationException('COD bank review permission denied');
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
            'match_id' => (int) $row->match_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
