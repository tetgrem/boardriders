<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only bank deposit CLAIM, not a bank-confirmed credit. */
final class CheckoutCodSettlementBankReceiptService
{
    public const RECORD_ABILITY = 'cod.settlement.bank-claim.record';
    public const SOURCE_KINDS = ['bank_statement_claim', 'manual_bank_claim'];

    /** @return array<string,mixed> */
    public function record(
        string $caseKey,
        string $operationKey,
        string $externalReference,
        int $claimedNetMinor,
        string $sourceKind = 'bank_statement_claim'
    ): array {
        $actor = $this->actor();
        if (!Str::isUuid($caseKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid bank claim UUID');
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D', $externalReference)) {
            throw new InvalidArgumentException('Invalid bank transaction reference');
        }
        if (!in_array($sourceKind, self::SOURCE_KINDS, true)
            || $claimedNetMinor < 0 || $claimedNetMinor > 100000000) {
            throw new InvalidArgumentException('Invalid bank deposit claim');
        }

        $caseKey = strtolower($caseKey);
        $operationKey = strtolower($operationKey);
        // No raw transaction identifiers are stored; same ref cannot be assigned to another order.
        $referenceHmac = $this->hmac(['cod-bank-reference-v1', $externalReference]);

        return DB::transaction(function () use (
            $caseKey, $operationKey, $referenceHmac,
            $claimedNetMinor, $sourceKind, $actor
        ): array {
            $case = DB::table('checkout_cod_settlement_cases')
                ->where('case_key', $caseKey)->lockForUpdate()->first();
            if ($case === null || $case->currency_code !== 'UAH') {
                throw new DomainException('UAH COD settlement case required');
            }

            $signature = $this->hmac([
                'cod-bank-claim-v1', $caseKey, $operationKey, $actor,
                (int) $case->id, $referenceHmac, $claimedNetMinor, $sourceKind,
            ]);
            $existing = DB::table('checkout_cod_settlement_bank_receipts')
                ->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ((int) $existing->case_id !== (int) $case->id
                    || !hash_equals((string) $existing->request_hmac, $signature)) {
                    throw new DomainException('Bank claim UUID reused for other parameters');
                }
                return $this->present($existing);
            }

            if (DB::table('checkout_cod_settlement_bank_receipts')
                ->where('reference_hmac', $referenceHmac)->exists()) {
                throw new DomainException('Bank reference already claimed by a COD case');
            }

            $id = DB::table('checkout_cod_settlement_bank_receipts')->insertGetId([
                'operation_key' => $operationKey,
                'case_id' => $case->id,
                'reference_hmac' => $referenceHmac,
                'source_kind' => $sourceKind,
                'claimed_net_minor' => $claimedNetMinor,
                'currency_code' => 'UAH',
                'recorded_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_settlement_bank_receipts')
                    ->where('id', $id)->first()
            );
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::RECORD_ABILITY)) {
            throw new AuthorizationException('COD bank claim permission denied');
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
            'claimed_net_minor' => (int) $row->claimed_net_minor,
            'currency_code' => (string) $row->currency_code,
            'source_kind' => (string) $row->source_kind,
            'recorded_by' => (int) $row->recorded_by,
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
