<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** An always-recomputed, PII-free, unposted double-entry draft. */
final class CheckoutCodJournalDraftEvaluator
{
    public function __construct(
        private readonly CheckoutCodBankCoverageEvaluator $coverage,
        private readonly CheckoutCodJournalDraftPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $packetKey, bool $lock = false): array
    {
        if (!Str::isUuid($packetKey)) {
            throw new InvalidArgumentException('Invalid COD coverage packet UUID');
        }

        $packetKey = strtolower($packetKey);

        $packet = DB::table('checkout_cod_bank_coverage_packets as p')
            ->leftJoin('checkout_cod_bank_coverage_reviews as r', 'r.packet_id', '=', 'p.id')
            ->where('p.operation_key', $packetKey)
            ->first([
                'p.id',
                'p.source_kind',
                'p.source_id',
                'p.reconciliation_id',
                'p.expected_orders_count',
                'p.bank_net_minor',
                'p.gross_minor',
                'p.fee_minor',
                'p.allocated_minor',
                'p.snapshot_hmac',
                'p.compiled_by',
                'r.decision',
                'r.reviewed_by',
            ]);

        if ($packet === null) {
            throw new DomainException('COD bank coverage packet missing');
        }

        $sourceTable = $packet->source_kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : 'checkout_cod_bank_batches';

        $sourceKey = DB::table($sourceTable)
            ->where('id', $packet->source_id)
            ->value('operation_key');

        if ($sourceKey === null) {
            throw new DomainException('COD bank coverage source missing');
        }

        $current = $this->coverage->inspect(
            (string) $packet->source_kind,
            (string) $sourceKey,
            $lock
        );

        $latest = (int) DB::table('checkout_cod_bank_coverage_packets')
            ->where('source_kind', $packet->source_kind)
            ->where('source_id', $packet->source_id)
            ->max('id');

        $coverageCurrent = (int) $packet->id === $latest
            && $packet->decision === 'retain_unverified'
            && $current['can_compile']
            && (int) $packet->reconciliation_id === $current['reconciliation_id']
            && hash_equals(
                (string) $packet->snapshot_hmac,
                (string) $current['snapshot_hmac']
            );

        $sourceLines = [];

        foreach ($current['lines'] as $line) {
            $sourceLines[$line['order_id']] = $line;
        }

        $persisted = DB::table('checkout_cod_bank_coverage_lines')
            ->where('packet_id', $packet->id)
            ->orderBy('order_id')
            ->get([
                'id',
                'order_id',
                'preflight_id',
                'gross_minor',
                'fee_minor',
                'allocated_minor',
            ]);

        $allLinesCurrent = $persisted->count() === (int) $packet->expected_orders_count
            && count($sourceLines) === $persisted->count();

        $gross = 0;
        $fee = 0;
        $net = 0;
        $entries = [];
        $lineFingerprints = [];

        foreach ($persisted as $line) {
            $upstream = $sourceLines[(int) $line->order_id] ?? null;

            if ($upstream === null
                || !$upstream['preflight_current']
                || (int) $upstream['preflight_id'] !== (int) $line->preflight_id
                || (int) $upstream['gross_minor'] !== (int) $line->gross_minor
                || (int) $upstream['fee_minor'] !== (int) $line->fee_minor
                || (int) $upstream['allocated_minor'] !== (int) $line->allocated_minor) {
                $allLinesCurrent = false;
            }

            $gross += (int) $line->gross_minor;
            $fee += (int) $line->fee_minor;
            $net += (int) $line->allocated_minor;

            $lineFingerprints[] = [
                (int) $line->id,
                (int) $line->order_id,
                (int) $line->preflight_id,
                (int) $line->gross_minor,
                (int) $line->fee_minor,
                (int) $line->allocated_minor,
            ];

            foreach ($this->policy->entries(
                (int) $line->gross_minor,
                (int) $line->fee_minor,
                (int) $line->allocated_minor
            ) as $posting) {
                $entries[] = array_merge($posting, [
                    'coverage_line_id' => (int) $line->id,
                    'order_id' => (int) $line->order_id,
                ]);
            }
        }

        $summary = $this->policy->summarize($entries);

        $allLinesCurrent = $allLinesCurrent
            && $gross === (int) $packet->gross_minor
            && $fee === (int) $packet->fee_minor
            && $net === (int) $packet->allocated_minor
            && $net === (int) $packet->bank_net_minor
            && $gross === $fee + $net
            && $summary['carrier_clearing_balance_minor'] === 0;

        $canPrepare = $coverageCurrent && $allLinesCurrent;

        $participants = array_values(array_unique(array_filter(array_merge(
            $current['participants'],
            [
                (int) $packet->compiled_by,
                (int) ($packet->reviewed_by ?? 0),
            ]
        ), static fn (int $id): bool => $id > 0)));

        sort($participants, SORT_NUMERIC);

        $result = [
            'packet_id' => (int) $packet->id,
            'source_kind' => (string) $packet->source_kind,
            'source_id' => (int) $packet->source_id,
            'orders_count' => $persisted->count(),
            'gross_minor' => $gross,
            'fee_minor' => $fee,
            'bank_minor' => $net,
            'can_prepare' => $canPrepare,
            'status' => match (true) {
                !$coverageCurrent => 'coverage_review_missing_or_stale',
                !$allLinesCurrent => 'coverage_lines_inconsistent',
                default => 'journal_draft_ready_unverified',
            },
            'entries' => $entries,
            'participants' => $participants,
        ];

        return array_merge($result, $summary, [
            'snapshot_hmac' => $this->hmac([
                'cod-journal-draft-snapshot-v1',
                $packetKey,
                (string) $current['snapshot_hmac'],
                (string) $packet->snapshot_hmac,
                (string) ($packet->decision ?? ''),
                $lineFingerprints,
                $result,
                $summary,
            ]),
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ]);
    }

    private function hmac(array $parts): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($parts, JSON_THROW_ON_ERROR),
            $key
        );
    }
}
