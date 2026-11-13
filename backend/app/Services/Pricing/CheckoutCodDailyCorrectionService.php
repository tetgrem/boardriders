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

/** Append-only COD report corrections with three independent human roles. */
final class CheckoutCodDailyCorrectionService
{
    public const VIEW_ABILITY = 'cod.operations.correction.view';
    public const PROPOSE_ABILITY = 'cod.operations.correction.propose';
    public const REVIEW_ABILITY = 'cod.operations.correction.review';
    public const PUBLISH_ABILITY = 'cod.operations.correction.publish';
    public const EXPORT_ABILITY = 'cod.operations.correction.export';

    private const REASONS = [
        'late_bank_booking', 'late_register',
        'late_financial_evidence', 'financial_projection_changed',
    ];

    private const ACTION_REASONS = [
        'approve' => 'evidence_reviewed',
        'reject' => 'insufficient_evidence',
        'publish' => 'approved_appendix',
    ];

    public function __construct(
        private readonly CheckoutCodDailyReportService $reports,
        private readonly CheckoutCodDailyCorrectionObservation $observations,
        private readonly CheckoutCodDailyCorrectionSigner $signer
    ) {
    }

    /** @return array<string,mixed> */
    public function propose(string $reportKey, string $operationKey, string $reason): array
    {
        $actor = $this->actor(self::PROPOSE_ABILITY);
        $this->assertAnalytics();
        $reportKey = $this->uuid($reportKey);
        $operationKey = $this->uuid($operationKey);
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Invalid COD correction reason');
        }
        $this->validReport($reportKey);

        return DB::transaction(function () use ($reportKey, $operationKey, $reason, $actor): array {
            $this->serializeWrites();
            $report = $this->reportRow($reportKey);

            $prior = DB::table('checkout_cod_daily_corrections')
                ->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if ((int) $prior->report_id !== (int) $report->id
                    || (int) $prior->proposed_by !== $actor
                    || (string) $prior->reason_code !== $reason) {
                    throw new DomainException('COD correction operation key has different intent');
                }
                $this->assertIntegrity($prior, $report);
                return $this->present($prior, $report, true);
            }

            if (DB::table('checkout_cod_daily_correction_events')
                ->where('event_key', $operationKey)->exists()) {
                throw new DomainException('COD correction UUID already used by another event');
            }
            if ($actor === (int) $report->sealed_by) {
                throw new DomainException('Daily report sealer cannot propose its correction');
            }

            $observation = $this->observations->observe($report);
            if (!$observation['has_changes']) {
                throw new DomainException('No late financial evidence for COD correction');
            }
            $this->assertReasonMatches($reason, $observation);

            $obsHmac = $this->signer->observation(
                $reportKey, (string) $report->payload_hmac, $observation
            );
            if (DB::table('checkout_cod_daily_corrections')
                ->where('report_id', $report->id)
                ->where('observation_hmac', $obsHmac)->exists()) {
                throw new DomainException('COD correction for this evidence already exists');
            }

            $correctionKey = (string) Str::uuid();
            $appendixHmac = $this->signer->appendix(
                $correctionKey, $operationKey, $reportKey,
                (string) $report->payload_hmac, $actor, $reason, $obsHmac, $observation
            );
            $now = CarbonImmutable::now('UTC');
            $id = (int) DB::table('checkout_cod_daily_corrections')->insertGetId([
                'report_id' => (int) $report->id,
                'correction_key' => $correctionKey,
                'operation_key' => $operationKey,
                'reason_code' => $reason,
                'base_report_hmac' => (string) $report->payload_hmac,
                'observation_json' => $this->signer->canonicalJson($observation),
                'observation_hmac' => $obsHmac,
                'appendix_hmac' => $appendixHmac,
                'proposed_by' => $actor,
                'created_at' => $now,
            ]);
            $this->insertEvent(
                $id, $correctionKey, $appendixHmac,
                $operationKey, 'proposed', $reason, $actor, null
            );
            $saved = DB::table('checkout_cod_daily_corrections')->where('id', $id)->first();
            return $this->present($saved, $report, false);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function act(
        string $correctionKey, string $operationKey, string $action, string $reason
    ): array {
        if (!array_key_exists($action, self::ACTION_REASONS)
            || self::ACTION_REASONS[$action] !== $reason) {
            throw new InvalidArgumentException('Invalid COD correction action/reason');
        }
        $ability = match ($action) {
            'approve', 'reject' => self::REVIEW_ABILITY,
            'publish' => self::PUBLISH_ABILITY,
        };
        $actor = $this->actor($ability);
        if ($action !== 'reject') {
            $this->assertAnalytics();
        }
        $correctionKey = $this->uuid($correctionKey);
        $operationKey = $this->uuid($operationKey);

        return DB::transaction(function () use ($correctionKey, $operationKey, $action, $reason, $actor): array {
            $this->serializeWrites();
            $correction = $this->correctionRow($correctionKey);
            $report = DB::table('checkout_cod_daily_reports')
                ->where('id', $correction->report_id)->first();
            $this->validReport((string) $report->report_key);
            $this->assertIntegrity($correction, $report);

            $prior = DB::table('checkout_cod_daily_correction_events')
                ->where('event_key', $operationKey)->first();
            if ($prior !== null) {
                if ((int) $prior->correction_id !== (int) $correction->id
                    || (string) $prior->event_kind !== $this->eventKind($action)
                    || (string) $prior->reason_code !== $reason
                    || (int) $prior->actor_id !== $actor) {
                    throw new DomainException('COD correction event UUID has different intent');
                }
                return $this->present($correction, $report, true);
            }
            if (DB::table('checkout_cod_daily_corrections')
                ->where('operation_key', $operationKey)->exists()) {
                throw new DomainException('COD correction UUID already used by proposal');
            }

            $last = $this->lastEvent((int) $correction->id);
            $status = $this->status((string) $last->event_kind);
            if ((in_array($action, ['approve', 'reject'], true) && $status !== 'proposed')
                || ($action === 'publish' && $status !== 'approved')) {
                throw new DomainException('Invalid COD correction workflow transition');
            }
            if (in_array($actor, [(int) $correction->proposed_by, (int) $report->sealed_by], true)) {
                throw new DomainException('Independent COD correction reviewer/publisher required');
            }
            if ($action === 'publish') {
                $reviewer = (int) DB::table('checkout_cod_daily_correction_events')
                    ->where('correction_id', $correction->id)
                    ->where('event_kind', 'approved')->value('actor_id');
                if ($actor === $reviewer) {
                    throw new DomainException('Reviewer cannot publish their correction');
                }
            }
            if ($action !== 'reject') {
                $this->assertFresh($correction, $report);
            }

            $this->insertEvent(
                (int) $correction->id, $correctionKey,
                (string) $correction->appendix_hmac, $operationKey,
                $this->eventKind($action), $reason, $actor, (string) $last->event_hmac
            );
            return $this->present($correction, $report, false);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function show(string $correctionKey): array
    {
        $this->actor(self::VIEW_ABILITY);
        $row = $this->correctionRow($this->uuid($correctionKey));
        $report = DB::table('checkout_cod_daily_reports')->where('id', $row->report_id)->first();
        $this->validReport((string) $report->report_key);
        $this->assertIntegrity($row, $report);
        return $this->present($row, $report, false);
    }

    /** @return array<string,mixed> */
    public function verify(string $correctionKey, bool $compareLive = false): array
    {
        $this->actor(self::VIEW_ABILITY);
        if ($compareLive) {
            $this->assertAnalytics();
        }
        $row = $this->correctionRow($this->uuid($correctionKey));
        $report = DB::table('checkout_cod_daily_reports')->where('id', $row->report_id)->first();
        $base = $this->reports->verify((string) $report->report_key);
        $integrity = $this->integrity($row, $report);
        $fresh = null;
        if ($compareLive && $integrity['valid'] && $base['integrity']['valid']) {
            $live = $this->observations->observe($report);
            $fresh = hash_equals(
                (string) $row->observation_hmac,
                $this->signer->observation((string) $report->report_key,
                    (string) $report->payload_hmac, $live)
            );
        }
        return [
            'correction_key' => (string) $row->correction_key,
            'report_key' => (string) $report->report_key,
            'integrity' => $integrity,
            'base_report_integrity' => $base['integrity'],
            'source_evidence_still_current' => $fresh,
            'late_change_is_not_archive_tampering' => true,
            'cod_paid' => false,
        ];
    }

    /** @return array{items:list<array<string,mixed>>,next_before_id:?int} */
    public function listing(string $reportKey, ?int $beforeId = null, int $limit = 20): array
    {
        $this->actor(self::VIEW_ABILITY);
        $this->paginate($beforeId, $limit);
        $key = $this->uuid($reportKey);
        $report = $this->reportRow($key);
        $q = DB::table('checkout_cod_daily_corrections')
            ->where('report_id', $report->id)->orderByDesc('id');
        if ($beforeId !== null) {
            $q->where('id', '<', $beforeId);
        }
        $rows = $q->limit($limit + 1)->get();
        $items = $rows->take($limit)->map(function ($row) use ($report): array {
            return $this->present($row, $report, false, false);
        })->values()->all();
        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['id'] : null,
        ];
    }

    /** @return array{items:list<array<string,mixed>>,next_before_id:?int} */
    public function history(string $correctionKey, ?int $beforeId = null, int $limit = 20): array
    {
        $this->actor(self::VIEW_ABILITY);
        $this->paginate($beforeId, $limit);
        $row = $this->correctionRow($this->uuid($correctionKey));
        $q = DB::table('checkout_cod_daily_correction_events')
            ->where('correction_id', $row->id)->orderByDesc('id');
        if ($beforeId !== null) {
            $q->where('id', '<', $beforeId);
        }
        $rows = $q->limit($limit + 1)->get();
        $items = $rows->take($limit)->map(static fn ($e): array => [
            'id' => (int) $e->id,
            'event_key' => (string) $e->event_key,
            'event_kind' => (string) $e->event_kind,
            'actor_id' => (int) $e->actor_id,
            'reason_code' => (string) $e->reason_code,
            'created_at' => (string) $e->created_at,
        ])->values()->all();
        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['id'] : null,
        ];
    }

    /** @return array{csv:string,correction_key:string,idempotent_replay:bool} */
    public function exportCsv(string $correctionKey, string $operationKey): array
    {
        $actor = $this->actor(self::EXPORT_ABILITY);
        $correctionKey = $this->uuid($correctionKey);
        $operationKey = $this->uuid($operationKey);

        return DB::transaction(function () use ($actor, $correctionKey, $operationKey): array {
            $this->serializeWrites();
            $row = $this->correctionRow($correctionKey);
            $report = DB::table('checkout_cod_daily_reports')->where('id', $row->report_id)->first();
            $this->validReport((string) $report->report_key);
            $this->assertIntegrity($row, $report);
            $last = $this->lastEvent((int) $row->id);
            if ($this->status((string) $last->event_kind) !== 'published') {
                throw new DomainException('Only published corrections can be exported');
            }
            $existing = DB::table('checkout_cod_daily_correction_events')
                ->where('event_key', $operationKey)->first();
            $replayed = $existing !== null;
            if ($existing !== null) {
                if ((int) $existing->correction_id !== (int) $row->id
                    || $existing->event_kind !== 'export_requested'
                    || (int) $existing->actor_id !== $actor) {
                    throw new DomainException('COD correction export UUID has different intent');
                }
            } else {
                if (DB::table('checkout_cod_daily_corrections')
                    ->where('operation_key', $operationKey)->exists()) {
                    throw new DomainException('COD correction export UUID reused from proposal');
                }
                $this->insertEvent(
                    (int) $row->id, $correctionKey, (string) $row->appendix_hmac,
                    $operationKey, 'export_requested', 'csv_appendix',
                    $actor, (string) $last->event_hmac
                );
            }
            return [
                'csv' => $this->csv($row, $report),
                'correction_key' => $correctionKey,
                'idempotent_replay' => $replayed,
            ];
        }, 3);
    }

    private function csv(object $correction, object $report): string
    {
        $observed = $this->decode((string) $correction->observation_json);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open correction CSV buffer');
        }
        try {
            $put = static function (array $row) use ($stream): void {
                if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
                    throw new \RuntimeException('Cannot write correction CSV');
                }
            };
            $put(['section', 'metric', 'before', 'after', 'delta', 'unit']);
            $put(['meta', 'report_date', (string) $report->report_date, '', '', 'UTC']);
            $put(['meta', 'correction_key', (string) $correction->correction_key, '', '', 'uuid']);
            $put(['meta', 'appendix_hmac', (string) $correction->appendix_hmac, '', '', 'sha256_hmac']);
            foreach ($observed['delta'] as $key => $values) {
                // Keys come from the finite, server-owned Observation::MONEY_KEYS list.
                $put(['financial', $key, $values['before'], $values['after'],
                    $values['difference'], str_ends_with($key, '_minor') ? 'UAH_minor' : 'count']);
            }
            foreach ($observed['late_signals'] as $key => $value) {
                $put(['late_evidence', $key, '', (int) $value, '',
                    str_ends_with($key, '_minor') ? 'UAH_minor' : 'count']);
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new \RuntimeException('Cannot read correction CSV');
            }
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private function assertFresh(object $correction, object $report): void
    {
        $live = $this->observations->observe($report);
        $now = $this->signer->observation(
            (string) $report->report_key, (string) $report->payload_hmac, $live
        );
        if (!hash_equals((string) $correction->observation_hmac, $now)) {
            throw new DomainException('COD correction evidence changed; create new proposal');
        }
    }

    private function assertReasonMatches(string $reason, array $o): void
    {
        $matches = match ($reason) {
            'late_bank_booking' => $o['late_signals']['late_booking_postings_count'] > 0,
            'late_register' => $o['late_signals']['late_register_claims_count'] > 0,
            'late_financial_evidence' => $o['late_signals']['late_signed_bank_reversals_count'] > 0
                || $o['late_signals']['late_gateway_reversal_reports_count'] > 0,
            'financial_projection_changed' => $o['delta'] !== [],
        };
        if (!$matches) {
            throw new DomainException('COD correction reason not supported by evidence');
        }
    }

    private function insertEvent(
        int $id, string $key, string $appendixHmac, string $eventKey,
        string $kind, string $reason, int $actor, ?string $previous
    ): void {
        DB::table('checkout_cod_daily_correction_events')->insert([
            'correction_id' => $id,
            'event_key' => $eventKey,
            'event_kind' => $kind,
            'reason_code' => $reason,
            'actor_id' => $actor,
            'appendix_hmac' => $appendixHmac,
            'previous_hmac' => $previous,
            'event_hmac' => $this->signer->event(
                $key, $eventKey, $kind, $actor, $reason, $previous, $appendixHmac
            ),
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /** @return array{valid:bool,appendix_valid:bool,event_chain_valid:bool,events_checked:int} */
    private function integrity(object $c, object $report): array
    {
        $o = $this->decode((string) $c->observation_json);
        $wantedObservation = $this->signer->observation(
            (string) $report->report_key, (string) $report->payload_hmac, $o
        );
        $wantedAppendix = $this->signer->appendix(
            (string) $c->correction_key, (string) $c->operation_key,
            (string) $report->report_key, (string) $report->payload_hmac,
            (int) $c->proposed_by, (string) $c->reason_code,
            (string) $c->observation_hmac, $o
        );
        $appendixValid = hash_equals((string) $c->base_report_hmac, (string) $report->payload_hmac)
            && hash_equals((string) $c->observation_hmac, $wantedObservation)
            && hash_equals((string) $c->appendix_hmac, $wantedAppendix);
        $events = DB::table('checkout_cod_daily_correction_events')
            ->where('correction_id', $c->id)->orderBy('id')->get();
        $prior = null;
        $lastKind = null;
        $reviewer = null;
        $eventsValid = $events->isNotEmpty();
        foreach ($events as $index => $e) {
            $allowed = match ($lastKind) {
                null => $e->event_kind === 'proposed'
                    && $e->event_key === $c->operation_key
                    && (int) $e->actor_id === (int) $c->proposed_by
                    && $e->reason_code === $c->reason_code
                    && (int) $c->proposed_by !== (int) $report->sealed_by,
                'proposed' => in_array($e->event_kind, ['approved', 'rejected'], true)
                    && !in_array((int) $e->actor_id,
                        [(int) $c->proposed_by, (int) $report->sealed_by], true),
                'approved' => $e->event_kind === 'published'
                    && !in_array((int) $e->actor_id,
                        [(int) $c->proposed_by, (int) $report->sealed_by, $reviewer], true),
                'published', 'export_requested' => $e->event_kind === 'export_requested',
                default => false,
            };
            $reason = match ($e->event_kind) {
                'proposed' => (string) $c->reason_code,
                'approved' => 'evidence_reviewed',
                'rejected' => 'insufficient_evidence',
                'published' => 'approved_appendix',
                'export_requested' => 'csv_appendix',
                default => null,
            };
            $expected = $this->signer->event(
                (string) $c->correction_key, (string) $e->event_key,
                (string) $e->event_kind, (int) $e->actor_id,
                (string) $e->reason_code, $prior, (string) $c->appendix_hmac
            );
            if (!$allowed || $reason !== $e->reason_code
                || $e->previous_hmac !== $prior
                || $e->appendix_hmac !== $c->appendix_hmac
                || !hash_equals((string) $e->event_hmac, $expected)) {
                $eventsValid = false;
            }
            if ($e->event_kind === 'approved') {
                $reviewer = (int) $e->actor_id;
            }
            $prior = (string) $e->event_hmac;
            $lastKind = (string) $e->event_kind;
        }
        return [
            'valid' => $appendixValid && $eventsValid,
            'appendix_valid' => $appendixValid,
            'event_chain_valid' => $eventsValid,
            'events_checked' => $events->count(),
        ];
    }

    private function assertIntegrity(object $c, object $report): void
    {
        if (!$this->integrity($c, $report)['valid']) {
            throw new DomainException('COD correction integrity verification failed');
        }
    }

    /** @return array<string,mixed> */
    private function present(object $c, object $report, bool $replay, bool $includeObservation = true): array
    {
        $event = $this->lastEvent((int) $c->id);
        $data = [
            'id' => (int) $c->id,
            'correction_key' => (string) $c->correction_key,
            'report_key' => (string) $report->report_key,
            'report_date' => (string) $report->report_date,
            'status' => $this->status((string) $event->event_kind),
            'reason_code' => (string) $c->reason_code,
            'proposed_by' => (int) $c->proposed_by,
            'created_at' => (string) $c->created_at,
            'base_report_hmac' => (string) $c->base_report_hmac,
            'appendix_hmac' => (string) $c->appendix_hmac,
            'last_event_id' => (int) $event->id,
            'idempotent_replay' => $replay,
            'cod_paid' => false,
            'customer_payment_verified' => false,
            'orders_payment_status_changed' => false,
        ];
        if ($includeObservation) {
            $data['observation'] = $this->decode((string) $c->observation_json);
        }
        return $data;
    }

    private function status(string $eventKind): string
    {
        return $eventKind === 'export_requested' ? 'published' : $eventKind;
    }

    private function eventKind(string $action): string
    {
        return match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'publish' => 'published',
        };
    }

    private function validReport(string $key): void
    {
        $v = $this->reports->verify($key);
        if (!$v['integrity']['valid']) {
            throw new DomainException('COD base daily report integrity invalid');
        }
    }

    private function reportRow(string $key): object
    {
        $row = DB::table('checkout_cod_daily_reports')->where('report_key', $key)->first();
        if ($row === null) {
            throw new DomainException('COD daily report not found');
        }
        return $row;
    }

    private function correctionRow(string $key): object
    {
        $row = DB::table('checkout_cod_daily_corrections')->where('correction_key', $key)->first();
        if ($row === null) {
            throw new DomainException('COD correction not found');
        }
        return $row;
    }

    private function lastEvent(int $id): object
    {
        $last = DB::table('checkout_cod_daily_correction_events')
            ->where('correction_id', $id)->orderByDesc('id')->first();
        if ($last === null) {
            throw new DomainException('COD correction audit is incomplete');
        }
        return $last;
    }

    private function uuid(string $key): string
    {
        if (!Str::isUuid($key)) {
            throw new InvalidArgumentException('Invalid COD correction UUID');
        }
        return strtolower($key);
    }

    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new DomainException('COD correction JSON invalid');
        }
        return $data;
    }

    private function paginate(?int $before, int $limit): void
    {
        if (($before !== null && $before < 1) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid COD correction pagination');
        }
    }

    private function serializeWrites(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(CAST(? AS BIGINT))', [460046]);
    }

    private function assertAnalytics(): void
    {
        $user = Auth::user();
        if (!Gate::forUser($user)->allows(CheckoutCodAnalyticsService::VIEW_ABILITY)) {
            throw new AuthorizationException('COD correction financial analytics denied');
        }
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows(CheckoutCodDailyReportService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('COD correction permission denied');
        }
        return (int) $id;
    }
}
