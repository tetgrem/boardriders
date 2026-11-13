<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Stores only a manually normalized NovaPay afterpayment register CLAIM. */
final class CheckoutCodNovaPayRegisterService
{
    public const CAPTURE_ABILITY = 'cod.novapay.register.capture';

    public function __construct(private readonly CheckoutCodNovaPayRegisterPolicy $policy)
    {
    }

    /** @param list<array<string,mixed>> $rows
     *  @return array<string,mixed>
     */
    public function capture(
        string $postingKey,
        string $operationKey,
        string $documentSha256,
        array $rows
    ): array {
        $actor = $this->actor();
        if (!Str::isUuid($postingKey) || !Str::isUuid($operationKey)
            || !preg_match('/^[a-f0-9]{64}$/D', $documentSha256)) {
            throw new InvalidArgumentException('Invalid NovaPay register metadata');
        }
        $postingKey = strtolower($postingKey);
        $operationKey = strtolower($operationKey);
        $requestHmac = $this->digest([
            'cod-novapay-register-request-v1', $postingKey, $operationKey,
            $documentSha256, $rows, $actor,
        ]);
        $old = DB::table('checkout_cod_novapay_register_captures')
            ->where('operation_key', $operationKey)->first();
        if ($old !== null) {
            return $this->replay($old, $requestHmac);
        }

        return DB::transaction(function () use (
            $postingKey, $operationKey, $documentSha256, $rows,
            $requestHmac, $actor
        ): array {
            $posting = DB::table('checkout_cod_bank_receipt_postings')
                ->where('operation_key', $postingKey)->first();
            if ($posting === null) {
                throw new DomainException('Posted COD bank credit required');
            }
            $expected = $this->expected((int) $posting->journal_draft_id);
            // Lock all affected orders before the posting and reference.
            DB::table('orders')->whereIn('id', array_column($expected, 'order_id'))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $posting = DB::table('checkout_cod_bank_receipt_postings')
                ->where('id', $posting->id)->lockForUpdate()->first();
            DB::table('checkout_cod_bank_reference_claims')
                ->where('reference_hmac', $posting->reference_hmac)
                ->lockForUpdate()->first(['id']);

            $old = DB::table('checkout_cod_novapay_register_captures')
                ->where('operation_key', $operationKey)->first();
            if ($old !== null) {
                return $this->replay($old, $requestHmac);
            }
            if (DB::table('checkout_cod_novapay_register_captures')
                ->where('bank_posting_id', $posting->id)->exists()) {
                throw new DomainException('COD bank posting already has a registry claim');
            }
            if ($actor === (int) $posting->posted_by
                || $this->hasHold((string) $posting->source_kind, (int) $posting->source_id)
                || DB::table('checkout_cod_privat_refetches')
                    ->where('source_kind', $posting->source_kind)
                    ->where('source_id', $posting->source_id)
                    ->where('result_code', '<>', 'consistent_unverified')->exists()) {
                throw new DomainException('Independent operator and undisputed receipt required');
            }
            $expected = $this->expected((int) $posting->journal_draft_id);
            if (count($expected) === 0
                || collect($expected)->contains(static fn (array $r): bool =>
                    $r['payment_status'] !== 'pending'
                    || $r['payment_method'] !== 'cash_on_delivery')) {
                throw new DomainException('COD register orders must be pending');
            }
            $comparison = $this->policy->compare(array_map(static fn (array $r): array => [
                'order_key' => $r['order_key'],
                'gross_minor' => $r['gross_minor'],
                'fee_minor' => $r['fee_minor'],
                'net_minor' => $r['net_minor'],
            ], $expected), $rows);
            $draft = DB::table('checkout_cod_journal_drafts')
                ->where('id', $posting->journal_draft_id)->first([
                    'orders_count', 'gross_minor', 'fee_minor', 'bank_minor',
                ]);
            if ($draft === null
                || $comparison['orders_count'] !== (int) $draft->orders_count
                || $comparison['gross_minor'] !== (int) $draft->gross_minor
                || $comparison['fee_minor'] !== (int) $draft->fee_minor
                || $comparison['bank_minor'] !== (int) $draft->bank_minor
                || $comparison['bank_minor'] !== (int) $posting->amount_minor) {
                throw new DomainException('NovaPay register totals differ from booked bank receipt');
            }
            $byKey = [];
            foreach ($expected as $e) {
                $byKey[$e['order_key']] = $e;
            }
            $snapshot = $this->digest([
                'cod-novapay-register-snapshot-v1', (int) $posting->id,
                $documentSha256, $comparison['lines'],
                (int) $draft->gross_minor, (int) $draft->fee_minor,
                (int) $draft->bank_minor,
            ]);
            $captureId = DB::table('checkout_cod_novapay_register_captures')->insertGetId([
                'operation_key' => $operationKey,
                'bank_posting_id' => $posting->id,
                'source_kind' => 'operator_extract',
                'document_sha256' => $documentSha256,
                'orders_count' => $comparison['orders_count'],
                'gross_minor' => $comparison['gross_minor'],
                'fee_minor' => $comparison['fee_minor'],
                'bank_minor' => $comparison['bank_minor'],
                'snapshot_hmac' => $snapshot,
                'captured_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);
            foreach ($comparison['lines'] as $r) {
                DB::table('checkout_cod_novapay_register_lines')->insert([
                    'capture_id' => $captureId,
                    'order_id' => $byKey[$r['order_key']]['order_id'],
                    'shipment_hmac' => $this->digest([
                        'cod-novapay-register-shipment-v1', $r['shipment_number'],
                    ]),
                    'external_payment_hmac' => $this->digest([
                        'cod-novapay-register-payment-v1', $r['external_payment_ref'],
                    ]),
                    'gross_minor' => $r['gross_minor'],
                    'fee_minor' => $r['fee_minor'],
                    'net_minor' => $r['net_minor'],
                    'created_at' => now(),
                ]);
            }
            DB::statement('SET CONSTRAINTS cod_npr_complete_at_commit IMMEDIATE');
            $saved = DB::table('checkout_cod_novapay_register_captures')
                ->where('id', $captureId)->first();
            return $this->present($saved, false);
        }, 3);
    }

    /** @return list<array<string,mixed>> */
    private function expected(int $draftId): array
    {
        return DB::table('checkout_cod_journal_drafts as d')
            ->join('checkout_cod_bank_coverage_lines as l', 'l.packet_id', '=', 'd.coverage_packet_id')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->join('checkout_payment_choices as pc', 'pc.order_id', '=', 'o.id')
            ->where('d.id', $draftId)->orderBy('o.id')
            ->get(['o.id as order_id', 'o.order_key', 'o.payment_status',
                'pc.payment_method', 'l.gross_minor', 'l.fee_minor', 'l.allocated_minor'])
            ->map(static fn (object $r): array => [
                'order_id' => (int) $r->order_id,
                'order_key' => strtolower((string) $r->order_key),
                'payment_status' => (string) $r->payment_status,
                'payment_method' => (string) $r->payment_method,
                'gross_minor' => (int) $r->gross_minor,
                'fee_minor' => (int) $r->fee_minor,
                'net_minor' => (int) $r->allocated_minor,
            ])->all();
    }

    private function hasHold(string $kind, int $sourceId): bool
    {
        return (int) DB::selectOne(
                'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) '
                . 'THEN 1 ELSE 0 END AS held', [$kind, $sourceId]
            )->held === 1;
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(self::CAPTURE_ABILITY)) {
            throw new AuthorizationException('NovaPay register capture denied');
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

    /** @return array<string,mixed> */
    private function replay(object $r, string $signature): array
    {
        if (!hash_equals((string) $r->request_hmac, $signature)) {
            throw new DomainException('NovaPay register UUID reused with different request');
        }
        return $this->present($r, true);
    }

    /** @return array<string,mixed> */
    private function present(object $r, bool $replay): array
    {
        return [
            'capture_id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'bank_posting_id' => (int) $r->bank_posting_id,
            'orders_count' => (int) $r->orders_count,
            'gross_minor' => (int) $r->gross_minor,
            'fee_minor' => (int) $r->fee_minor,
            'bank_minor' => (int) $r->bank_minor,
            'idempotent_replay' => $replay,
            'register_allocations_matched' => true,
            'novapay_origin_authenticated' => false,
            'shipment_ownership_verified' => false,
            'customer_payment_confirmed' => false,
            'orders_payment_status_changed' => false,
            'cod_paid' => false,
        ];
    }
}
