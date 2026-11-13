<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Manual COD operations case workflow. No payment or ledger writes.
 * Lock order on writes: bank posting -> operations case -> case event.
 */
final class CheckoutCodOperationsCaseService
{
    public const MANAGE_ABILITY = 'cod.operations.case.manage';

    public function __construct(
        private readonly CheckoutCodOperationsService $operations,
        private readonly CheckoutCodCaseWorkflowPolicy $policy,
    ) {
    }

    /** @return array<string,mixed> */
    public function open(string $postingKey, string $operationKey): array
    {
        $actor = $this->actor(true);
        $postingKey = $this->uuid($postingKey);
        $operationKey = $this->uuid($operationKey);

        $requestHmac = $this->digest([
            'cod-case-open-v42',
            $postingKey,
            $operationKey,
            $actor,
        ]);

        return DB::transaction(function () use (
            $postingKey,
            $operationKey,
            $requestHmac,
            $actor
        ): array {
            $posting = DB::table('checkout_cod_bank_receipt_postings')
                ->where('operation_key', $postingKey)
                ->lockForUpdate()
                ->first();

            if ($posting === null) {
                throw new DomainException('COD bank posting not found');
            }

            $existing = DB::table('checkout_cod_operations_cases')
                ->where('bank_posting_id', $posting->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $event = DB::table('checkout_cod_operations_case_events')
                    ->where('operation_key', $operationKey)
                    ->first();

                if (
                    $event !== null
                    && (int) $event->case_id === (int) $existing->id
                    && hash_equals(
                        (string) $event->request_hmac,
                        $requestHmac
                    )
                ) {
                    return $this->present($existing, $event, true);
                }

                throw new DomainException(
                    'COD posting already has an operations case'
                );
            }

            if (DB::table('checkout_cod_operations_case_events')
                ->where('operation_key', $operationKey)->exists()) {
                throw new DomainException(
                    'COD case operation UUID already used'
                );
            }

            $this->assertIndependent($posting, $actor);

            $state = $this->operations->inspect($postingKey);
            $now = now();
            $priority = (string) $state['priority'];

            $id = DB::table('checkout_cod_operations_cases')
                ->insertGetId([
                    'case_key' => $operationKey,
                    'bank_posting_id' => (int) $posting->id,
                    'case_status' => 'open',
                    'priority' => $priority,
                    'assigned_to' => null,
                    'snapshot_hmac' => $state['snapshot_hmac'],
                    'revision' => 1,
                    'sla_due_at' => $now->copy()->addHours(
                        $this->policy->slaHours($priority)
                    ),
                    'closed_at' => null,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->insertEvent([
                'case_id' => $id,
                'operation_key' => $operationKey,
                'revision' => 1,
                'event_action' => 'open',
                'reason_code' => 'initial_triage',
                'from_status' => null,
                'to_status' => 'open',
                'assigned_from' => null,
                'assigned_to' => null,
                'snapshot_hmac' => $state['snapshot_hmac'],
                'actor_id' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => $now,
            ]);

            $case = DB::table('checkout_cod_operations_cases')
                ->where('id', $id)
                ->first();

            $event = DB::table('checkout_cod_operations_case_events')
                ->where('case_id', $id)
                ->where('revision', 1)
                ->first();

            return $this->present($case, $event, false, $state);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function act(
        string $caseKey,
        string $operationKey,
        int $expectedRevision,
        string $expectedSnapshotHmac,
        string $action,
        string $reasonCode
    ): array {
        $actor = $this->actor(true);
        $caseKey = $this->uuid($caseKey);
        $operationKey = $this->uuid($operationKey);

        if (
            $expectedRevision < 1
            || !preg_match(
                '/^[a-f0-9]{64}$/D',
                $expectedSnapshotHmac
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid COD case revision or snapshot'
            );
        }

        $this->policy->assertReason($action, $reasonCode);

        $requestHmac = $this->digest([
            'cod-case-action-v42',
            $caseKey,
            $operationKey,
            $expectedRevision,
            $expectedSnapshotHmac,
            $action,
            $reasonCode,
            $actor,
        ]);

        return DB::transaction(function () use (
            $caseKey,
            $operationKey,
            $expectedRevision,
            $expectedSnapshotHmac,
            $action,
            $reasonCode,
            $requestHmac,
            $actor
        ): array {
            $probe = DB::table('checkout_cod_operations_cases')
                ->where('case_key', $caseKey)
                ->first(['bank_posting_id']);

            if ($probe === null) {
                throw new DomainException(
                    'COD operations case not found'
                );
            }

            $posting = DB::table('checkout_cod_bank_receipt_postings')
                ->where('id', $probe->bank_posting_id)
                ->lockForUpdate()
                ->first();

            if ($posting === null) {
                throw new DomainException(
                    'COD bank posting not found'
                );
            }

            $case = DB::table('checkout_cod_operations_cases')
                ->where('case_key', $caseKey)
                ->lockForUpdate()
                ->first();

            if (
                $case === null
                || (int) $case->bank_posting_id
                !== (int) $posting->id
            ) {
                throw new DomainException(
                    'COD case changed during processing'
                );
            }

            $event = DB::table('checkout_cod_operations_case_events')
                ->where('operation_key', $operationKey)
                ->first();

            if ($event !== null) {
                if (
                    (int) $event->case_id !== (int) $case->id
                    || !hash_equals(
                        (string) $event->request_hmac,
                        $requestHmac
                    )
                ) {
                    throw new DomainException(
                        'COD case operation UUID reused with different request'
                    );
                }

                return $this->present($case, $event, true);
            }

            $this->assertIndependent($posting, $actor);

            if (
                (int) $case->revision !== $expectedRevision
                || !hash_equals(
                    (string) $case->snapshot_hmac,
                    $expectedSnapshotHmac
                )
            ) {
                throw new DomainException(
                    'COD case revision changed; reload required'
                );
            }

            $state = $this->operations->inspect(
                (string) $posting->operation_key
            );

            $changed = !hash_equals(
                (string) $case->snapshot_hmac,
                (string) $state['snapshot_hmac']
            );

            $owner = $case->assigned_to !== null
                && (int) $case->assigned_to === $actor;

            $latest = $state['latest_review'];

            $clean = $state['status'] === 'awaiting_external_proof'
                && $state['priority'] === 'low'
                && $state['capture_id'] !== null
                && $state['requires_attention'] === false;

            $independentReview = is_array($latest)
                && $latest['action'] === 'acknowledge_comparison'
                && $latest['snapshot_current'] === true
                && (int) $latest['reviewed_by'] !== $actor;

            $next = $this->policy->next(
                (string) $case->case_status,
                $action,
                $owner,
                $changed,
                $clean,
                $independentReview
            );

            $assignee = match ($action) {
                'claim' => $actor,
                'release', 'refresh' => null,
                default => $case->assigned_to === null
                    ? null
                    : (int) $case->assigned_to,
            };

            $now = now();
            $priority = (string) $state['priority'];

            $update = [
                'case_status' => $next,
                'priority' => $priority,
                'assigned_to' => $assignee,
                'snapshot_hmac' => $state['snapshot_hmac'],
                'revision' => $expectedRevision + 1,
                'closed_at' => $next === 'closed_internal_review'
                    ? $now
                    : null,
                'updated_by' => $actor,
                'updated_at' => $now,
            ];

            if ($action === 'refresh') {
                $update['sla_due_at'] = $now->copy()->addHours(
                    $this->policy->slaHours($priority)
                );
            }

            $updated = DB::table('checkout_cod_operations_cases')
                ->where('id', $case->id)
                ->where('revision', $expectedRevision)
                ->update($update);

            if ($updated !== 1) {
                throw new DomainException(
                    'COD case revision race detected'
                );
            }

            $this->insertEvent([
                'case_id' => (int) $case->id,
                'operation_key' => $operationKey,
                'revision' => $expectedRevision + 1,
                'event_action' => $action,
                'reason_code' => $reasonCode,
                'from_status' => (string) $case->case_status,
                'to_status' => $next,
                'assigned_from' => $case->assigned_to,
                'assigned_to' => $assignee,
                'snapshot_hmac' => $state['snapshot_hmac'],
                'actor_id' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => $now,
            ]);

            $saved = DB::table('checkout_cod_operations_cases')
                ->where('id', $case->id)
                ->first();

            $event = DB::table('checkout_cod_operations_case_events')
                ->where('case_id', $case->id)
                ->where('revision', $expectedRevision + 1)
                ->first();

            return $this->present($saved, $event, false, $state);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function inspect(string $caseKey): array
    {
        $this->actor(false);

        $case = DB::table('checkout_cod_operations_cases')
            ->where('case_key', $this->uuid($caseKey))
            ->first();

        if ($case === null) {
            throw new DomainException('COD operations case not found');
        }

        return $this->present($case, null, false);
    }

    /**
     * Bounded keyset pagination.
     *
     * @return array<string,mixed>
     */
    public function queue(
        ?int $beforeId = null,
        int $limit = 20,
        string $filter = 'all'
    ): array {
        $actor = $this->actor(false);

        if (
            ($beforeId !== null && $beforeId < 1)
            || $limit < 1
            || $limit > 50
            || !in_array(
                $filter,
                ['all', 'active', 'mine', 'overdue', 'stale'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid COD case queue filters'
            );
        }

        $items = [];
        $cursor = $beforeId;
        $scanned = 0;

        do {
            $q = DB::table('checkout_cod_operations_cases')
                ->orderByDesc('id')
                ->limit(50);

            if ($cursor !== null) {
                $q->where('id', '<', $cursor);
            }

            $rows = $q->get();

            foreach ($rows as $row) {
                $cursor = (int) $row->id;
                $scanned++;

                $item = $this->present($row, null, false);

                $include = match ($filter) {
                    'all' => true,
                    'active' => $row->case_status
                        !== 'closed_internal_review',
                    'mine' => $row->assigned_to !== null
                        && (int) $row->assigned_to === $actor,
                    'overdue' => $item['sla_overdue'],
                    'stale' => $item['evidence_changed'],
                };

                if ($include) {
                    $items[] = $item;
                }

                if (count($items) >= $limit || $scanned >= 200) {
                    break;
                }
            }
        } while (
            $rows->count() === 50
            && count($items) < $limit
            && $scanned < 200
        );

        $more = $cursor !== null
            && DB::table('checkout_cod_operations_cases')
                ->where('id', '<', $cursor)
                ->exists();

        return [
            'items' => $items,
            'next_before_id' => $more ? $cursor : null,
            'scanned' => $scanned,
            'scan_limit_reached' => $scanned >= 200,
            'filter' => $filter,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function history(
        string $caseKey,
        ?int $beforeId = null,
        int $limit = 30
    ): array {
        $this->actor(false);

        if (
            ($beforeId !== null && $beforeId < 1)
            || $limit < 1
            || $limit > 100
        ) {
            throw new InvalidArgumentException(
                'Invalid COD case history filters'
            );
        }

        $case = DB::table('checkout_cod_operations_cases')
            ->where('case_key', $this->uuid($caseKey))
            ->first();

        if ($case === null) {
            throw new DomainException(
                'COD operations case not found'
            );
        }

        $q = DB::table('checkout_cod_operations_case_events')
            ->where('case_id', $case->id)
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($beforeId !== null) {
            $q->where('id', '<', $beforeId);
        }

        $rows = $q->get();
        $more = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $e): array => [
                'event_id' => (int) $e->id,
                'operation_key' => (string) $e->operation_key,
                'revision' => (int) $e->revision,
                'action' => (string) $e->event_action,
                'reason_code' => (string) $e->reason_code,
                'from_status' => $e->from_status,
                'to_status' => (string) $e->to_status,
                'assigned_from' => $e->assigned_from === null
                    ? null
                    : (int) $e->assigned_from,
                'assigned_to' => $e->assigned_to === null
                    ? null
                    : (int) $e->assigned_to,
                'actor_id' => (int) $e->actor_id,
                'created_at' => (string) $e->created_at,
            ])
            ->values()
            ->all();

        return [
            'case_key' => (string) $case->case_key,
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['event_id']
                : null,
            'cod_paid' => false,
        ];
    }

    /** @param array<string,mixed> $values */
    private function insertEvent(array $values): void
    {
        DB::table('checkout_cod_operations_case_events')
            ->insert($values);
    }

    /** @return array<string,mixed> */
    private function present(
        object $case,
        ?object $event,
        bool $replay,
        ?array $state = null
    ): array {
        $posting = DB::table('checkout_cod_bank_receipt_postings')
            ->where('id', $case->bank_posting_id)
            ->first(['operation_key']);

        if ($posting === null) {
            throw new DomainException(
                'COD bank posting not found'
            );
        }

        $state ??= $this->operations->inspect(
            (string) $posting->operation_key
        );

        $changed = !hash_equals(
            (string) $case->snapshot_hmac,
            (string) $state['snapshot_hmac']
        );

        return [
            'case_id' => (int) $case->id,
            'case_key' => (string) $case->case_key,
            'posting_key' => (string) $posting->operation_key,
            'case_status' => (string) $case->case_status,
            'priority' => (string) $case->priority,
            'current_priority' => (string) $state['priority'],
            'assigned_to' => $case->assigned_to === null
                ? null
                : (int) $case->assigned_to,
            'revision' => (int) $case->revision,
            'snapshot_hmac' => (string) $case->snapshot_hmac,
            'current_snapshot_hmac' => (string) $state['snapshot_hmac'],
            'evidence_changed' => $changed,
            'current_financial_status' => (string) $state['status'],
            'current_financial_flags' => $state['flags'],
            'sla_due_at' => (string) $case->sla_due_at,
            'sla_overdue' => $case->case_status
                !== 'closed_internal_review'
                && now()->greaterThan($case->sla_due_at),
            'closed_at' => $case->closed_at,
            'event_id' => $event === null
                ? null
                : (int) $event->id,
            'event_revision' => $event === null
                ? null
                : (int) $event->revision,
            'idempotent_replay' => $replay,
            'external_payment_authenticated' => false,
            'orders_payment_status_changed' => false,
            'automatic_payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    private function assertIndependent(
        object $posting,
        int $actor
    ): void {
        $capture = DB::table(
            'checkout_cod_novapay_register_captures'
        )
            ->where('bank_posting_id', $posting->id)
            ->first(['captured_by']);

        if (
            $actor === (int) $posting->posted_by
            || (
                $capture !== null
                && $actor === (int) $capture->captured_by
            )
        ) {
            throw new DomainException(
                'Independent COD case operator required'
            );
        }
    }

    private function uuid(string $value): string
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException(
                'Invalid COD operation UUID'
            );
        }

        return strtolower($value);
    }

    private function digest(array $data): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $key
        );
    }

    private function actor(bool $write): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (
                !is_int($id)
                && !(is_string($id) && ctype_digit($id))
            )
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
            || (
                $write
                && !Gate::forUser($user)->allows(
                    self::MANAGE_ABILITY
                )
            )
        ) {
            throw new AuthorizationException(
                'COD case workflow permission denied'
            );
        }

        return (int) $id;
    }
}
