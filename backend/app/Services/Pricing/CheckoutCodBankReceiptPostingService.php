<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Posts only a bank-credit/clearing pair to the internal ledger. No order payment update. */
final class CheckoutCodBankReceiptPostingService
{
    public const POST_ABILITY = 'cod.bank-receipt.post';

    public function __construct(
        private readonly CheckoutCodBankReceiptPostingEvaluator $evaluator,
        private readonly CheckoutCodPrivatStatementClient $client,
        private readonly CheckoutCodPrivatRefetchPolicy $compare
    ) {
    }

    /** @return array<string,mixed> */
    public function post(string $journalKey, string $refetchKey, string $operationKey): array
    {
        $actor = $this->actor();
        foreach ([$journalKey, $refetchKey, $operationKey] as $key) {
            if (!Str::isUuid($key)) {
                throw new InvalidArgumentException('Invalid COD bank receipt posting UUID');
            }
        }
        $journalKey = strtolower($journalKey);
        $refetchKey = strtolower($refetchKey);
        $operationKey = strtolower($operationKey);
        $requestHmac = $this->hmac([
            'cod-bank-receipt-post-request-v1', $journalKey, $refetchKey, $operationKey, $actor,
        ]);

        $previous = DB::table('checkout_cod_bank_receipt_postings')
            ->where('operation_key', $operationKey)->first();
        if ($previous !== null) {
            $this->assertReplay($previous, $requestHmac);
            return $this->present($previous, true);
        }

        // Read a previously reviewed journal and an independently re-fetched bank line.
        $before = $this->evaluator->inspect($journalKey, $refetchKey);
        $this->assertIndependent($actor, $before['participants']);
        $cfg = $this->client->configuration();
        if ($before['account_alias'] !== $cfg['alias']) {
            throw new DomainException('COD bank posting account mismatch');
        }

        // Live authenticated HTTPS bank read, outside any database transaction.
        $fetched = $this->client->fetch($before['booked_on'], $before['booked_on'], $cfg);
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        $verified = $this->compare->compare(
            $fetched['rows'], $cfg['alias'], $before['reference_hmac'],
            $before['transaction_hmac'], $before['row_hmac'],
            $before['amount_minor'], $before['booked_on'], $key
        );
        if ($verified['result_code'] !== 'consistent_unverified'
            || $verified['reference_rows'] !== 1
            || $verified['exact_rows'] !== 1) {
            throw new DomainException('Fresh bank credit no longer matches ledger claim');
        }

        return DB::transaction(function () use (
            $actor, $before, $journalKey, $refetchKey, $operationKey,
            $requestHmac, $fetched, $cfg
        ): array {
            $sourceTable = $before['source_kind'] === 'single'
                ? 'checkout_cod_settlement_bank_receipts'
                : 'checkout_cod_bank_batches';
            $source = DB::table($sourceTable)->where('id', $before['source_id'])
                ->lockForUpdate()->first(['id', 'reference_hmac']);
            if ($source === null || $source->reference_hmac !== $before['reference_hmac']) {
                throw new DomainException('Bank credit source changed');
            }
            DB::table('checkout_cod_bank_reference_claims')
                ->where('reference_hmac', $source->reference_hmac)
                ->lockForUpdate()->first(['id']);

            // Refetch and COD approvals serialize using this same bank-reference lock.
            $now = $this->evaluator->inspect($journalKey, $refetchKey);
            $this->assertIndependent($actor, $now['participants']);
            if (!hash_equals($before['snapshot_hmac'], $now['snapshot_hmac'])
                || $now['account_alias'] !== $cfg['alias']) {
                throw new DomainException('COD bank receipt evidence changed during HTTPS request');
            }
            $prior = DB::table('checkout_cod_bank_receipt_postings')
                ->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                $this->assertReplay($prior, $requestHmac);
                return $this->present($prior, true);
            }
            if (DB::table('checkout_cod_bank_receipt_postings')
                ->where('source_kind', $now['source_kind'])
                ->where('source_id', $now['source_id'])->exists()) {
                throw new DomainException('Bank receipt has already been posted');
            }

            $id = DB::table('checkout_cod_bank_receipt_postings')->insertGetId([
                'operation_key' => $operationKey,
                'journal_draft_id' => $now['journal_draft_id'],
                'refetch_id' => $now['refetch_id'],
                'statement_entry_id' => $now['statement_entry_id'],
                'source_kind' => $now['source_kind'],
                'source_id' => $now['source_id'],
                'reference_hmac' => $now['reference_hmac'],
                'transaction_hmac' => $now['transaction_hmac'],
                'row_hmac' => $now['row_hmac'],
                'account_alias' => $now['account_alias'],
                'booked_on' => $now['booked_on'],
                'amount_minor' => $now['amount_minor'],
                'api_result_sha256' => $fetched['result_sha256'],
                'snapshot_hmac' => $now['snapshot_hmac'],
                'posted_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);
            foreach ($now['lines'] as $line) {
                DB::table('checkout_cod_bank_receipt_ledger_lines')->insert([
                    'posting_id' => $id,
                    'side' => $line['side'],
                    'account_code' => $line['account_code'],
                    'amount_minor' => $line['amount_minor'],
                    'created_at' => now(),
                ]);
            }
            DB::statement('SET CONSTRAINTS cod_brp_balance_at_commit IMMEDIATE');
            return $this->present(DB::table('checkout_cod_bank_receipt_postings')
                ->where('id', $id)->first(), false);
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::POST_ABILITY)) {
            throw new AuthorizationException('COD bank receipt posting permission denied');
        }
        return (int) $id;
    }

    private function assertIndependent(int $actor, array $participants): void
    {
        if (in_array($actor, $participants, true)) {
            throw new DomainException('Independent bank receipt posting operator required');
        }
    }

    private function hmac(array $items): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($items, JSON_THROW_ON_ERROR), $key);
    }

    private function assertReplay(object $old, string $hmac): void
    {
        if (!hash_equals((string) $old->request_hmac, $hmac)) {
            throw new DomainException('Bank receipt posting UUID reused');
        }
    }

    /** @return array<string,mixed> */
    private function present(object $p, bool $replay): array
    {
        return [
            'posting_id' => (int) $p->id,
            'operation_key' => (string) $p->operation_key,
            'source_kind' => (string) $p->source_kind,
            'source_id' => (int) $p->source_id,
            'journal_draft_id' => (int) $p->journal_draft_id,
            'amount_minor' => (int) $p->amount_minor,
            'currency_code' => 'UAH',
            'ledger_lines_count' => 2,
            'idempotent_replay' => $replay,
            'bank_credit_booked_in_internal_ledger' => true,
            'bank_native_signature_verified' => false,
            'customer_payment_confirmed' => false,
            'orders_payment_status_changed' => false,
            'cod_paid' => false,
        ];
    }
}
