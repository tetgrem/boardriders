<?php

namespace App\Services\Pricing;

use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read-only bank statement import.
 * No payment-status or ledger mutations.
 */
final class CheckoutCodPrivatStatementImportService
{
    public const IMPORT_ABILITY =
        'cod.privatbank.statement.import';

    public function __construct(
        private readonly CheckoutCodPrivatStatementClient $client
    ) {
    }

    /** @return array<string,mixed> */
    public function import(
        string $operationKey,
        string $from,
        string $to
    ): array {
        $actor = $this->actor();

        if (!Str::isUuid($operationKey)) {
            throw new InvalidArgumentException(
                'Invalid statement import UUID'
            );
        }

        $operationKey = strtolower($operationKey);
        $this->validatePeriod($from, $to);

        $cfg = $this->client->configuration();
        $alias = $cfg['alias'];

        $signature = $this->hmac([
            'cod-privatbank-import-v1',
            $operationKey,
            $alias,
            $from,
            $to,
            $actor,
        ]);

        $previous = DB::table(
            'checkout_cod_privat_import_runs'
        )
            ->where('operation_key', $operationKey)
            ->first();

        if ($previous !== null) {
            $this->assertReplay($previous, $signature);

            return $this->present(
                $previous,
                true
            );
        }

        // Never keep a DB transaction open during HTTP requests.
        $fetched = $this->client->fetch(
            $from,
            $to,
            $cfg
        );

        return DB::transaction(function () use (
            $operationKey,
            $from,
            $to,
            $alias,
            $actor,
            $signature,
            $fetched
        ): array {
            $old = DB::table(
                'checkout_cod_privat_import_runs'
            )
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                $this->assertReplay($old, $signature);

                return $this->present(
                    $old,
                    true
                );
            }

            $runId = DB::table(
                'checkout_cod_privat_import_runs'
            )->insertGetId([
                'operation_key' => $operationKey,
                'account_alias' => $alias,
                'start_date' => $from,
                'end_date' => $to,
                'page_count' => $fetched['page_count'],
                'received_count' => $fetched['received_count'],
                'posted_count' => count($fetched['rows']),
                'skipped_count' => $fetched['skipped_count'],
                'imported_by' => $actor,
                'request_hmac' => $signature,
                'result_sha256' => $fetched['result_sha256'],
                'created_at' => now(),
            ]);

            foreach ($fetched['rows'] as $row) {
                $txHash = $this->hmac([
                    'cod-privatbank-tx-v1',
                    $alias,
                    $row['ref'],
                    $row['refn'],
                ]);

                $rowHash = $this->hmac([
                    'cod-privatbank-row-v1',
                    $alias,
                    $row['ref'],
                    $row['refn'],
                    $row['direction'],
                    $row['amount_minor'],
                    $row['booked_on'],
                ]);

                $referenceHash = preg_match(
                    '/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D',
                    $row['ref']
                )
                    ? $this->hmac([
                        'cod-bank-reference-v1',
                        $row['ref'],
                    ])
                    : null;

                // Serialize against concurrent COD reconciliation.
                if ($referenceHash !== null) {
                    DB::table(
                        'checkout_cod_bank_reference_claims'
                    )
                        ->where(
                            'reference_hmac',
                            $referenceHash
                        )
                        ->lockForUpdate()
                        ->first(['id']);
                }

                $inserted = DB::table(
                    'checkout_cod_privat_statement_entries'
                )->insertOrIgnore([
                    'first_run_id' => $runId,
                    'account_alias' => $alias,
                    'transaction_hmac' => $txHash,
                    'reference_hmac' => $referenceHash,
                    'direction' => $row['direction'],
                    'amount_minor' => $row['amount_minor'],
                    'booked_on' => $row['booked_on'],
                    'row_hmac' => $rowHash,
                    'created_at' => now(),
                ]);

                if ($inserted !== 1) {
                    $existing = DB::table(
                        'checkout_cod_privat_statement_entries'
                    )
                        ->where('account_alias', $alias)
                        ->where('transaction_hmac', $txHash)
                        ->first();

                    if (
                        $existing === null
                        || !hash_equals(
                            (string) $existing->row_hmac,
                            $rowHash
                        )
                        || $existing->reference_hmac
                        !== $referenceHash
                    ) {
                        throw new DomainException(
                            'Conflicting version of a bank transaction'
                        );
                    }
                }
            }

            return $this->present(
                DB::table(
                    'checkout_cod_privat_import_runs'
                )
                    ->where('id', $runId)
                    ->first(),
                false
            );
        }, 3);
    }

    private function validatePeriod(
        string $from,
        string $to
    ): void {
        $a = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $from
        );

        $b = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $to
        );

        if (
            !$a
            || !$b
            || $a->format('Y-m-d') !== $from
            || $b->format('Y-m-d') !== $to
            || $a > $b
            || $a->diff($b)->days > 6
            || $to > now('Europe/Kyiv')->toDateString()
        ) {
            throw new InvalidArgumentException(
                'Statement period must be 1–7 valid days'
            );
        }
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id)
                && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                self::IMPORT_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Statement import permission denied'
            );
        }

        return (int) $id;
    }

    private function hmac(array $data): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException(
                'APP_KEY missing'
            );
        }

        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $key
        );
    }

    private function assertReplay(
        object $row,
        string $signature
    ): void {
        if (!hash_equals(
            (string) $row->request_hmac,
            $signature
        )) {
            throw new DomainException(
                'Statement import UUID reused with other parameters'
            );
        }
    }

    /** @return array<string,mixed> */
    private function present(
        object $row,
        bool $replay
    ): array {
        $new = DB::table(
            'checkout_cod_privat_statement_entries'
        )
            ->where('first_run_id', $row->id)
            ->count();

        return [
            'run_id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'account_alias' => (string) $row->account_alias,
            'start_date' => (string) $row->start_date,
            'end_date' => (string) $row->end_date,
            'page_count' => (int) $row->page_count,
            'received_count' => (int) $row->received_count,
            'posted_count' => (int) $row->posted_count,
            'skipped_count' => (int) $row->skipped_count,
            'first_seen_transactions_count' => $new,
            'idempotent_replay' => $replay,
            'bank_api_read_success' => true,
            'bank_native_signature_verified' => false,
            'cod_paid' => false,
            'refund_executed' => false,
        ];
    }
}
