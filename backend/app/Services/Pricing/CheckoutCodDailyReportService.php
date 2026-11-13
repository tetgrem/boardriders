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
use RuntimeException;

/**
 * Once-per-completed-UTC-day immutable operational COD snapshots.
 * No customer payment recognition, no accounting close or mutations to orders.
 */
final class CheckoutCodDailyReportService
{
    public const VIEW_ABILITY = 'cod.operations.daily_report.view';
    public const SEAL_ABILITY = 'cod.operations.daily_report.seal';
    public const EXPORT_ABILITY = 'cod.operations.daily_report.export';

    public function __construct(
        private readonly CheckoutCodAnalyticsService $analytics,
        private readonly CheckoutCodDailyReportSigner $signer,
        private readonly CheckoutCodDailyReportReconciliation $reconciler
    ) {
    }

    /** @return array<string,mixed> */
    public function seal(string $day, string $operationKey): array
    {
        $actor = $this->actor(self::SEAL_ABILITY);
        $operationKey = $this->uuid($operationKey);
        $window = CheckoutCodAnalyticsWindow::make($day, $day);

        if ($window->start->greaterThanOrEqualTo(
            CarbonImmutable::now('UTC')->startOfDay()
        )) {
            throw new DomainException('COD daily report can only seal a completed UTC day');
        }

        return DB::transaction(function () use ($day, $operationKey, $actor): array {
            // Daily sealing is infrequent: serialize across days to avoid both
            // same-day races and cross-day operation-key races.
            DB::select('SELECT pg_advisory_xact_lock(CAST(? AS BIGINT))', [450045]);

            $prior = DB::table('checkout_cod_daily_reports')
                ->where('operation_key', $operationKey)->first();

            if ($prior !== null) {
                if ((string) $prior->report_date !== $day
                    || (int) $prior->sealed_by !== $actor) {
                    throw new DomainException('COD daily report operation key has different intent');
                }

                $this->requireValidIntegrity($prior);

                return $this->present($prior, true);
            }

            if (DB::table('checkout_cod_daily_reports')
                ->where('report_date', $day)->exists()) {
                throw new DomainException('COD daily report already sealed for this date');
            }

            // Analytics service independently checks its own VIEW_ABILITY.
            $payload = $this->analytics->report($day, $day);

            if (($payload['staff']['truncated'] ?? true) === true) {
                throw new DomainException('COD daily staff analytics truncated; cannot seal');
            }

            $reconciliation = $this->reconciler->assess($payload);
            $reportKey = (string) Str::uuid();

            $digest = $this->signer->snapshot(
                $day, $operationKey, $actor, $payload, $reconciliation
            );

            $at = CarbonImmutable::now('UTC');

            $id = (int) DB::table('checkout_cod_daily_reports')->insertGetId([
                'report_key' => $reportKey,
                'report_date' => $day,
                'operation_key' => $operationKey,
                'schema_version' => 1,
                'payload_json' => $this->signer->canonicalJson($payload),
                'reconciliation_json' => $this->signer->canonicalJson($reconciliation),
                'payload_hmac' => $digest,
                'sealed_by' => $actor,
                'sealed_at' => $at,
            ]);

            $this->recordEvent(
                $id, $reportKey, $digest, $operationKey, 'sealed', $actor
            );

            $row = DB::table('checkout_cod_daily_reports')
                ->where('id', $id)->first();

            return $this->present($row, false);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function show(string $reportKey): array
    {
        $this->actor(self::VIEW_ABILITY);
        $row = $this->find($reportKey);

        $this->requireValidIntegrity($row);

        return $this->present($row, false);
    }

    /** @return array{items:list<array<string,mixed>>,next_before_id:?int} */
    public function listing(?int $beforeId = null, int $limit = 20): array
    {
        $this->actor(self::VIEW_ABILITY);

        if (($beforeId !== null && $beforeId < 1) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid COD daily report pagination');
        }

        $query = DB::table('checkout_cod_daily_reports')->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        $items = $rows->take($limit)->map(function (object $row): array {
            $recon = $this->decode((string) $row->reconciliation_json);

            return [
                'id' => (int) $row->id,
                'report_key' => (string) $row->report_key,
                'report_date' => (string) $row->report_date,
                'schema_version' => (int) $row->schema_version,
                'reconciliation_status' => (string) $recon['status'],
                'sealed_by' => (int) $row->sealed_by,
                'sealed_at' => (string) $row->sealed_at,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['id']
                : null,
        ];
    }

    /** @return array{items:list<array<string,mixed>>,next_before_id:?int} */
    public function history(
        string $reportKey,
        ?int $beforeId = null,
        int $limit = 20
    ): array {
        $this->actor(self::VIEW_ABILITY);

        if (($beforeId !== null && $beforeId < 1) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid COD daily event pagination');
        }

        $row = $this->find($reportKey);

        $query = DB::table('checkout_cod_daily_report_events')
            ->where('report_id', $row->id)
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $events = $query->limit($limit + 1)->get();
        $more = $events->count() > $limit;

        $items = $events->take($limit)->map(static fn (object $e): array => [
            'event_id' => (int) $e->id,
            'event_key' => (string) $e->event_key,
            'event_kind' => (string) $e->event_kind,
            'actor_id' => (int) $e->actor_id,
            'created_at' => (string) $e->created_at,
        ])->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['event_id']
                : null,
        ];
    }

    /** @return array<string,mixed> */
    public function verify(string $reportKey, bool $compareLive = false): array
    {
        $this->actor(self::VIEW_ABILITY);

        $row = $this->find($reportKey);
        $integrity = $this->integrity($row);
        $changed = null;

        if ($compareLive) {
            // Only the analytics permission may trigger live financial re-reads.
            $this->actor(CheckoutCodAnalyticsService::VIEW_ABILITY);

            if ($integrity['valid']) {
                $stored = $this->decode((string) $row->payload_json);
                $live = $this->analytics->report(
                    (string) $row->report_date,
                    (string) $row->report_date
                );

                unset(
                    $stored['period']['as_of_utc'],
                    $live['period']['as_of_utc']
                );

                $changed = !hash_equals(
                    $this->signer->canonicalJson($stored),
                    $this->signer->canonicalJson($live)
                );
            }
        }

        return [
            'report_key' => (string) $row->report_key,
            'report_date' => (string) $row->report_date,
            'integrity' => $integrity,
            'live_projection_differs' => $changed,
            'live_difference_is_not_archive_tampering' => true,
            'customer_payment_verified' => false,
        ];
    }

    /**
     * @return array{
     *   report_date:string,
     *   report_key:string,
     *   csv:string,
     *   idempotent_replay:bool
     * }
     */
    public function exportCsv(string $reportKey, string $operationKey): array
    {
        $actor = $this->actor(self::EXPORT_ABILITY);
        $operationKey = $this->uuid($operationKey);
        $row = $this->find($reportKey);

        $this->requireValidIntegrity($row);

        $csv = $this->makeCsv($row);

        $replayed = DB::transaction(
            function () use ($row, $operationKey, $actor): bool {
                // Serialise export idempotency with sealing and other exports.
                DB::select(
                    'SELECT pg_advisory_xact_lock(CAST(? AS BIGINT))',
                    [450045]
                );

                $existing = DB::table('checkout_cod_daily_report_events')
                    ->where('event_key', $operationKey)
                    ->first();

                if ($existing !== null) {
                    if ((int) $existing->report_id !== (int) $row->id
                        || $existing->event_kind !== 'export_requested'
                        || (int) $existing->actor_id !== $actor) {
                        throw new DomainException(
                            'COD daily export key has different intent'
                        );
                    }

                    return true;
                }

                $this->recordEvent(
                    (int) $row->id,
                    (string) $row->report_key,
                    (string) $row->payload_hmac,
                    $operationKey,
                    'export_requested',
                    $actor
                );

                return false;
            },
            3
        );

        return [
            'report_date' => (string) $row->report_date,
            'report_key' => (string) $row->report_key,
            'csv' => $csv,
            'idempotent_replay' => $replayed,
        ];
    }

    private function makeCsv(object $row): string
    {
        $payload = $this->decode((string) $row->payload_json);
        $recon = $this->decode((string) $row->reconciliation_json);

        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Cannot create COD daily CSV buffer');
        }

        try {
            $put = static function (array $cells) use ($stream): void {
                if (fputcsv($stream, $cells, ',', '"', '', "\r\n") === false) {
                    throw new RuntimeException('Cannot write COD daily CSV');
                }
            };

            $put(['section', 'metric', 'value', 'unit']);
            $put(['meta', 'report_date', (string) $row->report_date, 'UTC']);
            $put(['meta', 'report_key', (string) $row->report_key, 'uuid']);
            $put(['meta', 'schema_version', (int) $row->schema_version, 'version']);
            $put(['meta', 'payload_hmac', (string) $row->payload_hmac, 'sha256_hmac']);
            $put([
                'meta',
                'reconciliation_status',
                (string) $recon['status'],
                'status',
            ]);

            foreach ($recon['issues'] as $issue) {
                $put(['reconciliation', 'issue', (string) $issue, 'code']);
            }

            $f = $payload['financial'];

            foreach ([
                         'postings_count' => 'count',
                         'posted_bank_minor' => 'UAH_minor',
                         'journal_gross_minor' => 'UAH_minor',
                         'journal_fee_minor' => 'UAH_minor',
                         'register_claims_count' => 'count',
                         'missing_register_count' => 'count',
                         'claimed_register_gross_minor' => 'UAH_minor',
                         'claimed_register_fee_minor' => 'UAH_minor',
                         'claimed_register_net_minor' => 'UAH_minor',
                         'posting_journal_mismatch_count' => 'count',
                         'register_amount_mismatch_count' => 'count',
                     ] as $field => $unit) {
                $put([
                    'financial',
                    $field,
                    (int) $f[$field],
                    $unit,
                ]);
            }

            $cases = $payload['cases'];

            foreach ([
                         'created_count',
                         'open_count',
                         'in_progress_count',
                         'waiting_external_count',
                         'closed_internal_review_count',
                         'active_overdue_count',
                         'closed_within_sla_count',
                     ] as $field) {
                $put([
                    'cases',
                    $field,
                    (int) $cases[$field],
                    'count',
                ]);
            }

            $put([
                'cases',
                'closed_sla_hit_rate_bp',
                $cases['closed_sla_hit_rate_bp'] === null
                    ? ''
                    : (int) $cases['closed_sla_hit_rate_bp'],
                'basis_points',
            ]);

            $n = $payload['notifications'];

            foreach ([
                         'outbox_created_count',
                         'outbox_delivered_count',
                         'outbox_suppressed_count',
                         'outbox_pending_count',
                         'crm_inbox_items_created_count',
                         'crm_inbox_read_count',
                     ] as $field) {
                $put([
                    'notifications',
                    $field,
                    (int) $n[$field],
                    'count',
                ]);
            }

            foreach ($payload['staff']['items'] as $staff) {
                $id = (int) $staff['actor_id'];

                foreach ([
                             'active_cases_count',
                             'closed_cases_count',
                             'overdue_active_count',
                         ] as $field) {
                    $put([
                        'staff',
                        "actor_{$id}_{$field}",
                        (int) $staff[$field],
                        'count',
                    ]);
                }
            }

            foreach ($payload['daily'] as $day) {
                $put([
                    'daily',
                    'date',
                    (string) $day['date'],
                    'UTC',
                ]);
                $put([
                    'daily',
                    'postings_count',
                    (int) $day['postings_count'],
                    'count',
                ]);
                $put([
                    'daily',
                    'posted_bank_minor',
                    (int) $day['posted_bank_minor'],
                    'UAH_minor',
                ]);
            }

            rewind($stream);
            $text = stream_get_contents($stream);

            if ($text === false) {
                throw new RuntimeException('Cannot read COD daily CSV buffer');
            }

            return $text;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{
     *   valid:bool,
     *   report_hash_valid:bool,
     *   events_hash_valid:bool,
     *   seal_event_valid:bool,
     *   events_checked:int
     * }
     */
    private function integrity(object $row): array
    {
        $payload = $this->decode((string) $row->payload_json);
        $reconciliation = $this->decode((string) $row->reconciliation_json);

        $expected = $this->signer->snapshot(
            (string) $row->report_date,
            (string) $row->operation_key,
            (int) $row->sealed_by,
            $payload,
            $reconciliation
        );

        $reportOkay = (int) $row->schema_version === 1
            && hash_equals(
                (string) $row->payload_hmac,
                $expected
            );

        $eventsOkay = true;
        $sealCount = 0;
        $eventCount = 0;

        $events = DB::table('checkout_cod_daily_report_events')
            ->where('report_id', $row->id)
            ->orderBy('id')
            ->cursor();

        foreach ($events as $event) {
            $eventCount++;

            $want = $this->signer->event(
                (string) $event->event_key,
                (string) $event->event_kind,
                (string) $row->report_key,
                (int) $event->actor_id,
                (string) $event->report_hmac
            );

            if (!hash_equals((string) $event->event_hmac, $want)
                || !hash_equals(
                    (string) $event->report_hmac,
                    (string) $row->payload_hmac
                )
                || !in_array(
                    (string) $event->event_kind,
                    ['sealed', 'export_requested'],
                    true
                )) {
                $eventsOkay = false;
            }

            if ($event->event_kind === 'sealed') {
                $sealCount++;

                if ((string) $event->event_key !== (string) $row->operation_key
                    || (int) $event->actor_id !== (int) $row->sealed_by) {
                    $eventsOkay = false;
                }
            }
        }

        $sealOkay = $sealCount === 1;

        return [
            'valid' => $reportOkay && $eventsOkay && $sealOkay,
            'report_hash_valid' => $reportOkay,
            'events_hash_valid' => $eventsOkay,
            'seal_event_valid' => $sealOkay,
            'events_checked' => $eventCount,
        ];
    }

    private function requireValidIntegrity(object $row): void
    {
        if (!$this->integrity($row)['valid']) {
            throw new DomainException(
                'COD daily report integrity verification failed'
            );
        }
    }

    private function recordEvent(
        int $reportId,
        string $reportKey,
        string $reportHash,
        string $eventKey,
        string $kind,
        int $actor
    ): void {
        DB::table('checkout_cod_daily_report_events')->insert([
            'report_id' => $reportId,
            'event_key' => $eventKey,
            'event_kind' => $kind,
            'actor_id' => $actor,
            'report_hmac' => $reportHash,
            'event_hmac' => $this->signer->event(
                $eventKey,
                $kind,
                $reportKey,
                $actor,
                $reportHash
            ),
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /** @return array<string,mixed> */
    private function present(object $row, bool $replayed): array
    {
        return [
            'id' => (int) $row->id,
            'report_key' => (string) $row->report_key,
            'report_date' => (string) $row->report_date,
            'schema_version' => (int) $row->schema_version,
            'payload' => $this->decode((string) $row->payload_json),
            'reconciliation' => $this->decode((string) $row->reconciliation_json),
            'payload_hmac' => (string) $row->payload_hmac,
            'sealed_by' => (int) $row->sealed_by,
            'sealed_at' => (string) $row->sealed_at,
            'idempotent_replay' => $replayed,
            'customer_payment_confirmed' => false,
            'automatic_payment_posting_allowed' => false,
            'orders_payment_status_changed' => false,
            'cod_paid' => false,
        ];
    }

    private function find(string $reportKey): object
    {
        $key = $this->uuid($reportKey);

        $row = DB::table('checkout_cod_daily_reports')
            ->where('report_key', $key)
            ->first();

        if ($row === null) {
            throw new DomainException('COD daily report not found');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $value = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($value)) {
            throw new RuntimeException('Invalid COD daily report payload');
        }

        return $value;
    }

    private function uuid(string $key): string
    {
        if (!Str::isUuid($key)) {
            throw new InvalidArgumentException(
                'Invalid COD daily operation/report UUID'
            );
        }

        return strtolower($key);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException(
                'COD daily report permission denied'
            );
        }

        return (int) $id;
    }
}
