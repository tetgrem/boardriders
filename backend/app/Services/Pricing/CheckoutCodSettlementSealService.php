<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Immutable internal allocation only. No provenance of a customer's COD
 * collection is asserted here; in particular this NEVER changes orders.
 */
final class CheckoutCodSettlementSealService
{
    public const VIEW_ABILITY = 'cod.operations.settlement_seal.view';
    public const SEAL_ABILITY = 'cod.operations.settlement_seal.create';

    public function __construct(
        private readonly CheckoutCodSettlementSealSigner $signer,
        private readonly CheckoutCodRefetchRiskService $risk
    ) {
    }

    public function preview(string $postingKey): array
    {
        $this->actor(self::VIEW_ABILITY);
        $posting = $this->posting($postingKey);
        $seal = DB::table('checkout_cod_settlement_seals')
            ->where('posting_id', $posting->id)->first();
        if ($seal !== null) {
            $this->requireIntegrity($seal);
            return array_merge($this->present($seal, false), [
                'integrity_valid' => true,
                'current_financial_risk' => $this->financialRisk($posting),
            ]);
        }
        $capture = DB::table('checkout_cod_novapay_register_captures')
            ->where('bank_posting_id', $posting->id)->first();
        if ($capture === null) {
            return [
                'status' => 'awaiting_operator_register',
                'posting_id' => (int) $posting->id,
                'eligible_for_internal_seal' => false,
                'novapay_origin_authenticated' => false,
                'customer_payment_confirmed' => false,
                'cod_paid' => false,
            ];
        }
        $result = $this->assess($posting, $capture);
        return [
            'status' => $result['eligible']
                ? 'ready_for_internal_allocation_seal'
                : 'blocked_internal_allocation',
            'posting_id' => (int) $posting->id,
            'capture_id' => (int) $capture->id,
            'eligible_for_internal_seal' => $result['eligible'],
            'issues' => $result['issues'],
            'totals' => $result['snapshot']['totals'],
            'lines' => $result['snapshot']['lines'],
            'novapay_origin_authenticated' => false,
            'customer_payment_confirmed' => false,
            'cod_paid' => false,
        ];
    }

    public function seal(string $postingKey, string $operationKey): array
    {
        $actor = $this->actor(self::SEAL_ABILITY);
        $postingKey = $this->uuid($postingKey);
        $operationKey = $this->uuid($operationKey);

        return DB::transaction(function () use ($postingKey, $operationKey, $actor): array {
            $posting = DB::table('checkout_cod_bank_receipt_postings')
                ->where('operation_key', $postingKey)->lockForUpdate()->first();
            if ($posting === null) {
                throw new DomainException('COD posted bank receipt not found');
            }

            $prior = DB::table('checkout_cod_settlement_seals')
                ->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if ((int) $prior->posting_id !== (int) $posting->id
                    || (int) $prior->sealed_by !== $actor) {
                    throw new DomainException('COD settlement seal UUID has different intent');
                }
                $this->requireIntegrity($prior);
                return $this->present($prior, true);
            }
            if (DB::table('checkout_cod_settlement_seals')
                ->where('posting_id', $posting->id)->exists()) {
                throw new DomainException('COD posting allocation already sealed');
            }
            $capture = DB::table('checkout_cod_novapay_register_captures')
                ->where('bank_posting_id', $posting->id)->lockForUpdate()->first();
            if ($capture === null) {
                throw new DomainException('Operator register extract absent');
            }
            if (in_array($actor, [(int) $posting->posted_by, (int) $capture->captured_by], true)) {
                throw new DomainException('Independent COD allocation sealer required');
            }

            // Ordered locks avoid a race with another order-level transition.
            $orderIds = DB::table('checkout_cod_novapay_register_lines')
                ->where('capture_id', $capture->id)->orderBy('order_id')->pluck('order_id')->all();
            if ($orderIds === []) {
                throw new DomainException('Empty COD settlement register');
            }
            DB::table('orders')->whereIn('id', $orderIds)
                ->orderBy('id')->lockForUpdate()->get(['id']);

            $assessment = $this->assess($posting, $capture);
            if (!$assessment['eligible']) {
                throw new DomainException(
                    'COD settlement allocation blocked: ' . implode(', ', $assessment['issues'])
                );
            }
            $snapshot = $assessment['snapshot'];
            $sealKey = (string) Str::uuid();
            $hmac = $this->signer->digest($sealKey, $operationKey, $actor, $snapshot);
            $at = CarbonImmutable::now('UTC');

            $id = (int) DB::table('checkout_cod_settlement_seals')->insertGetId([
                'seal_key' => $sealKey,
                'operation_key' => $operationKey,
                'posting_id' => (int) $posting->id,
                'capture_id' => (int) $capture->id,
                'orders_count' => (int) $capture->orders_count,
                'gross_minor' => (int) $capture->gross_minor,
                'fee_minor' => (int) $capture->fee_minor,
                'bank_minor' => (int) $capture->bank_minor,
                'snapshot_json' => $this->signer->json($snapshot),
                'snapshot_hmac' => $hmac,
                'sealed_by' => $actor,
                'created_at' => $at,
            ]);
            foreach ($snapshot['lines'] as $line) {
                DB::table('checkout_cod_settlement_seal_lines')->insert([
                    'seal_id' => $id,
                    'register_line_id' => $line['register_line_id'],
                    'order_id' => $line['order_id'],
                    'shipment_hmac' => $line['shipment_hmac'],
                    'external_payment_hmac' => $line['external_payment_hmac'],
                    'gross_minor' => $line['gross_minor'],
                    'fee_minor' => $line['fee_minor'],
                    'net_minor' => $line['net_minor'],
                    'created_at' => $at,
                ]);
            }
            $row = DB::table('checkout_cod_settlement_seals')->where('id', $id)->first();
            return $this->present($row, false);
        }, 3);
    }

    public function show(string $sealKey): array
    {
        $this->actor(self::VIEW_ABILITY);
        $row = DB::table('checkout_cod_settlement_seals')
            ->where('seal_key', $this->uuid($sealKey))->first();
        if ($row === null) {
            throw new DomainException('COD settlement allocation seal not found');
        }
        $this->requireIntegrity($row);
        $posting = DB::table('checkout_cod_bank_receipt_postings')
            ->where('id', $row->posting_id)->first();
        return array_merge($this->present($row, false), [
            'integrity_valid' => true,
            'current_financial_risk' => $this->financialRisk($posting),
        ]);
    }

    private function assess(object $posting, object $capture): array
    {
        $issues = [];
        if ($this->financialRisk($posting)) {
            $issues[] = 'bank_source_disputed_or_held';
        }
        $journal = DB::table('checkout_cod_journal_drafts')
            ->where('id', $posting->journal_draft_id)->first();
        if ($journal === null
            || (int) $journal->gross_minor !== (int) $capture->gross_minor
            || (int) $journal->fee_minor !== (int) $capture->fee_minor
            || (int) $journal->bank_minor !== (int) $capture->bank_minor
            || (int) $posting->amount_minor !== (int) $capture->bank_minor
            || (int) $capture->gross_minor !== (int) $capture->fee_minor + (int) $capture->bank_minor) {
            $issues[] = 'posting_journal_register_header_mismatch';
        }
        $ledger = DB::table('checkout_cod_bank_receipt_ledger_lines')
            ->where('posting_id', $posting->id)
            ->get(['side', 'account_code', 'amount_minor']);
        $debit = $ledger->firstWhere('side', 'debit');
        $credit = $ledger->firstWhere('side', 'credit');
        if ($ledger->count() !== 2 || $debit === null || $credit === null
            || $debit->account_code !== 'cod_bank_asset'
            || $credit->account_code !== 'cod_carrier_clearing'
            || (int) $debit->amount_minor !== (int) $posting->amount_minor
            || (int) $credit->amount_minor !== (int) $posting->amount_minor) {
            $issues[] = 'internal_bank_ledger_unbalanced';
        }
        $lines = DB::table('checkout_cod_novapay_register_lines as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->join('checkout_payment_choices as pc', 'pc.order_id', '=', 'o.id')
            ->join('order_financial_snapshots as f', 'f.order_id', '=', 'o.id')
            ->join('checkout_cod_bank_coverage_lines as cov', function ($j) use ($journal): void {
                $j->on('cov.order_id', '=', 'o.id');
                $j->where('cov.packet_id', '=', (int) ($journal?->coverage_packet_id ?? 0));
            })
            ->where('l.capture_id', $capture->id)
            ->orderBy('l.order_id')
            ->get([
                'l.id as register_line_id', 'l.order_id', 'l.shipment_hmac',
                'l.external_payment_hmac', 'l.gross_minor', 'l.fee_minor',
                'l.net_minor', 'o.payment_status', 'pc.payment_method',
                'f.goods_payable_minor', 'f.currency_code',
                'cov.gross_minor as covered_gross', 'cov.fee_minor as covered_fee',
                'cov.allocated_minor as covered_net',
            ]);
        $summary = ['gross_minor' => 0, 'fee_minor' => 0, 'bank_minor' => 0];
        $clean = [];
        $orderIds = [];
        foreach ($lines as $row) {
            $bad = $row->payment_status !== 'pending'
                || $row->payment_method !== 'cash_on_delivery'
                || $row->currency_code !== 'UAH'
                || (int) $row->goods_payable_minor !== (int) $row->gross_minor
                || (int) $row->gross_minor !== (int) $row->covered_gross
                || (int) $row->fee_minor !== (int) $row->covered_fee
                || (int) $row->net_minor !== (int) $row->covered_net
                || (int) $row->gross_minor !== (int) $row->fee_minor + (int) $row->net_minor;
            if ($bad) {
                $issues[] = 'order_amount_or_method_mismatch';
            }
            $orderIds[] = (int) $row->order_id;
            foreach (['gross_minor', 'fee_minor'] as $field) {
                $summary[$field] += (int) $row->{$field};
            }
            $summary['bank_minor'] += (int) $row->net_minor;
            $clean[] = [
                'register_line_id' => (int) $row->register_line_id,
                'order_id' => (int) $row->order_id,
                'shipment_hmac' => (string) $row->shipment_hmac,
                'external_payment_hmac' => (string) $row->external_payment_hmac,
                'gross_minor' => (int) $row->gross_minor,
                'fee_minor' => (int) $row->fee_minor,
                'net_minor' => (int) $row->net_minor,
            ];
        }
        if ($lines->count() !== (int) $capture->orders_count
            || $summary !== [
                'gross_minor' => (int) $capture->gross_minor,
                'fee_minor' => (int) $capture->fee_minor,
                'bank_minor' => (int) $capture->bank_minor,
            ]) {
            $issues[] = 'incomplete_register_allocations';
        }
        if ($orderIds !== []) {
            foreach ([
                         'checkout_payment_capture_receipts',
                         'checkout_payment_capture_decisions',
                         'checkout_payment_attempts',
                         'checkout_cod_settlement_seal_lines',
                     ] as $table) {
                if (DB::table($table)->whereIn('order_id', $orderIds)->exists()) {
                    $issues[] = 'order_already_has_payment_or_seal';
                    break;
                }
            }
        }
        $snapshot = [
            'schema_version' => 1,
            'bank_posting_id' => (int) $posting->id,
            'bank_source_kind' => (string) $posting->source_kind,
            'bank_source_id' => (int) $posting->source_id,
            'reference_hmac' => (string) $posting->reference_hmac,
            'bank_statement_entry_id' => (int) $posting->statement_entry_id,
            'bank_posting_hmac' => (string) $posting->snapshot_hmac,
            'journal_draft_hmac' => (string) ($journal?->snapshot_hmac ?? ''),
            'register_capture_id' => (int) $capture->id,
            'register_document_sha256' => (string) $capture->document_sha256,
            'register_snapshot_hmac' => (string) $capture->snapshot_hmac,
            'totals' => array_merge(['orders_count' => (int) $capture->orders_count], $summary),
            'lines' => $clean,
            'novapay_origin_authenticated' => false,
            'shipment_ownership_verified' => false,
            'customer_payment_confirmed' => false,
        ];
        return [
            'eligible' => $issues === [],
            'issues' => array_values(array_unique($issues)),
            'snapshot' => $snapshot,
        ];
    }

    private function financialRisk(object $p): bool
    {
        $hold = (int) DB::selectOne(
                'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) THEN 1 ELSE 0 END AS held',
                [(string) $p->source_kind, (int) $p->source_id]
            )->held === 1;
        $negative = (bool) $this->risk->inspect((string) $p->source_kind, (int) $p->source_id)['blocks_financial_approval'];
        $reversal = DB::table('checkout_cod_bank_evidence_events')
                ->where('reference_hmac', $p->reference_hmac)->where('event_kind', 'reversal')->exists()
            || DB::table('checkout_cod_gateway_reports')
                ->where('reference_hmac', $p->reference_hmac)->where('event_kind', 'reversal_reported')->exists();
        return $hold || $negative || $reversal;
    }

    private function requireIntegrity(object $row): void
    {
        if (!$this->integrity($row)) {
            throw new DomainException('COD settlement seal integrity verification failed');
        }
    }

    private function integrity(object $row): bool
    {
        $snapshot = json_decode((string) $row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) {
            return false;
        }
        $want = $this->signer->digest(
            (string) $row->seal_key, (string) $row->operation_key,
            (int) $row->sealed_by, $snapshot
        );
        if (!hash_equals((string) $row->snapshot_hmac, $want)) {
            return false;
        }
        $lines = DB::table('checkout_cod_settlement_seal_lines')
            ->where('seal_id', $row->id)->orderBy('order_id')
            ->get(['register_line_id', 'order_id', 'shipment_hmac',
                'external_payment_hmac', 'gross_minor', 'fee_minor', 'net_minor'])
            ->map(static fn (object $l): array => [
                'register_line_id' => (int) $l->register_line_id,
                'order_id' => (int) $l->order_id,
                'shipment_hmac' => (string) $l->shipment_hmac,
                'external_payment_hmac' => (string) $l->external_payment_hmac,
                'gross_minor' => (int) $l->gross_minor,
                'fee_minor' => (int) $l->fee_minor,
                'net_minor' => (int) $l->net_minor,
            ])->all();
        return isset($snapshot['lines'])
            && is_array($snapshot['lines'])
            && array_is_list($snapshot['lines'])
            && $this->signer->json($snapshot['lines']) === $this->signer->json($lines)
            && (int) ($snapshot['bank_posting_id'] ?? -1) === (int) $row->posting_id
            && (int) ($snapshot['register_capture_id'] ?? -1) === (int) $row->capture_id
            && (int) ($snapshot['totals']['orders_count'] ?? -1) === (int) $row->orders_count
            && (int) ($snapshot['totals']['gross_minor'] ?? -1) === (int) $row->gross_minor
            && (int) ($snapshot['totals']['fee_minor'] ?? -1) === (int) $row->fee_minor
            && (int) ($snapshot['totals']['bank_minor'] ?? -1) === (int) $row->bank_minor;
    }

    private function present(object $row, bool $replay): array
    {
        $snapshot = json_decode((string) $row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
        return [
            'status' => 'sealed_awaiting_authenticated_collection',
            'seal_key' => (string) $row->seal_key,
            'operation_key' => (string) $row->operation_key,
            'posting_id' => (int) $row->posting_id,
            'capture_id' => (int) $row->capture_id,
            'totals' => $snapshot['totals'],
            'lines' => $snapshot['lines'],
            'snapshot_hmac' => (string) $row->snapshot_hmac,
            'sealed_by' => (int) $row->sealed_by,
            'created_at' => (string) $row->created_at,
            'idempotent_replay' => $replay,
            'internal_allocation_sealed' => true,
            'novapay_origin_authenticated' => false,
            'shipment_ownership_verified' => false,
            'customer_payment_confirmed' => false,
            'orders_payment_status_changed' => false,
            'cod_paid' => false,
        ];
    }

    private function posting(string $postingKey): object
    {
        $row = DB::table('checkout_cod_bank_receipt_postings')
            ->where('operation_key', $this->uuid($postingKey))->first();
        if ($row === null) {
            throw new DomainException('COD posted bank receipt not found');
        }
        return $row;
    }

    private function uuid(string $key): string
    {
        if (!Str::isUuid($key)) {
            throw new InvalidArgumentException('Invalid COD settlement seal UUID');
        }
        return strtolower($key);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('COD settlement allocation permission denied');
        }
        return (int) $id;
    }
}
