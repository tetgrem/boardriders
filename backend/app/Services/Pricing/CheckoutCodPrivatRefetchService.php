<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** New authenticated HTTPS read, append-only comparison. Never posts a payment. */
final class CheckoutCodPrivatRefetchService
{
    public const RECHECK_ABILITY = 'cod.privatbank.refetch.recheck';

    public function __construct(
        private readonly CheckoutCodPrivatStatementClient $client,
        private readonly CheckoutCodPrivatRefetchPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function recheck(string $kind, string $sourceKey, string $operationKey): array
    {
        $actor = $this->actor();
        if (!in_array($kind, ['single', 'batch'], true)
            || !Str::isUuid($sourceKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD bank refetch request');
        }
        $sourceKey = strtolower($sourceKey);
        $operationKey = strtolower($operationKey);
        $requestHmac = $this->digest([
            'cod-privat-refetch-request-v1', $kind, $sourceKey, $operationKey, $actor,
        ]);
        $old = DB::table('checkout_cod_privat_refetches')
            ->where('operation_key', $operationKey)->first();
        if ($old !== null) {
            $this->checkReplay($old, $requestHmac);
            return $this->present($old, true);
        }

        // Never hold a DB lock while making an external HTTP request.
        $cfg = $this->client->configuration();
        $baseline = $this->baseline($kind, $sourceKey, $cfg['alias']);
        if (in_array($actor, [$baseline['recorded_by'], $baseline['imported_by']], true)) {
            throw new DomainException('Independent bank refetch operator required');
        }
        $fetched = $this->client->fetch(
            $baseline['booked_on'], $baseline['booked_on'], $cfg
        );
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        $assessment = $this->policy->compare(
            $fetched['rows'], $cfg['alias'], $baseline['reference_hmac'],
            $baseline['transaction_hmac'], $baseline['row_hmac'],
            $baseline['claimed_net_minor'], $baseline['booked_on'], $key
        );

        return DB::transaction(function () use (
            $actor, $kind, $sourceKey, $operationKey, $requestHmac,
            $cfg, $baseline, $fetched, $assessment
        ): array {
            $sourceTable = $kind === 'single'
                ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';
            DB::table($sourceTable)->where('id', $baseline['source_id'])
                ->lockForUpdate()->first(['id']);
            DB::table('checkout_cod_bank_reference_claims')
                ->where('reference_hmac', $baseline['reference_hmac'])
                ->lockForUpdate()->first(['id']);
            $current = $this->baseline($kind, $sourceKey, $cfg['alias']);
            if ($current !== $baseline) {
                throw new DomainException('Original bank statement changed during refetch');
            }
            $old = DB::table('checkout_cod_privat_refetches')
                ->where('operation_key', $operationKey)->first();
            if ($old !== null) {
                $this->checkReplay($old, $requestHmac);
                return $this->present($old, true);
            }
            $id = DB::table('checkout_cod_privat_refetches')->insertGetId([
                'operation_key' => $operationKey,
                'source_kind' => $kind,
                'source_id' => $baseline['source_id'],
                'statement_entry_id' => $baseline['entry_id'],
                'reference_hmac' => $baseline['reference_hmac'],
                'transaction_hmac' => $baseline['transaction_hmac'],
                'row_hmac' => $baseline['row_hmac'],
                'booked_on' => $baseline['booked_on'],
                'amount_minor' => $baseline['claimed_net_minor'],
                'result_code' => $assessment['result_code'],
                'reference_rows' => $assessment['reference_rows'],
                'identity_rows' => $assessment['identity_rows'],
                'exact_rows' => $assessment['exact_rows'],
                'api_result_sha256' => $fetched['result_sha256'],
                'rechecked_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);
            return $this->present(
                DB::table('checkout_cod_privat_refetches')->where('id', $id)->first(), false
            );
        }, 3);
    }

    /** @return array<string,int|string> */
    private function baseline(string $kind, string $sourceKey, string $alias): array
    {
        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';
        $source = DB::table($table)->where('operation_key', $sourceKey)
            ->first(['id', 'recorded_by', 'reference_hmac', 'claimed_net_minor']);
        if ($source === null) {
            throw new DomainException('COD bank source not found');
        }
        $entries = DB::table('checkout_cod_privat_statement_entries as e')
            ->join('checkout_cod_privat_import_runs as r', 'r.id', '=', 'e.first_run_id')
            ->where('e.reference_hmac', $source->reference_hmac)
            ->get(['e.id', 'e.account_alias', 'e.direction', 'e.amount_minor',
                'e.transaction_hmac', 'e.row_hmac', 'e.booked_on', 'r.imported_by']);
        if ($entries->count() !== 1) {
            throw new DomainException('Unique imported statement row required');
        }
        $entry = $entries->first();
        if ($entry->account_alias !== $alias || $entry->direction !== 'credit'
            || (int) $entry->amount_minor !== (int) $source->claimed_net_minor) {
            throw new DomainException('Imported bank credit does not match COD claim');
        }
        return [
            'source_id' => (int) $source->id,
            'recorded_by' => (int) $source->recorded_by,
            'imported_by' => (int) $entry->imported_by,
            'entry_id' => (int) $entry->id,
            'reference_hmac' => (string) $source->reference_hmac,
            'transaction_hmac' => (string) $entry->transaction_hmac,
            'row_hmac' => (string) $entry->row_hmac,
            'booked_on' => (string) $entry->booked_on,
            'claimed_net_minor' => (int) $source->claimed_net_minor,
        ];
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::RECHECK_ABILITY)) {
            throw new AuthorizationException('COD bank refetch permission denied');
        }
        return (int) $id;
    }

    private function digest(array $parts): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), $key);
    }

    private function checkReplay(object $old, string $signature): void
    {
        if (!hash_equals((string) $old->request_hmac, $signature)) {
            throw new DomainException('COD bank refetch UUID reused');
        }
    }

    /** @return array<string,mixed> */
    private function present(object $row, bool $replay): array
    {
        return [
            'refetch_id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'source_kind' => (string) $row->source_kind,
            'source_id' => (int) $row->source_id,
            'statement_entry_id' => (int) $row->statement_entry_id,
            'result_code' => (string) $row->result_code,
            'reference_rows' => (int) $row->reference_rows,
            'identity_rows' => (int) $row->identity_rows,
            'exact_rows' => (int) $row->exact_rows,
            'refetched_at' => (string) $row->created_at,
            'idempotent_replay' => $replay,
            'bank_api_read_success' => true,
            'bank_native_signature_verified' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
