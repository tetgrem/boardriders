<?php

declare(strict_types=1);

namespace App\Services\Fiscal63;

use App\Jobs\Fiscal62PollJob;
use App\Services\Fiscal62\Fiscal62Integrity;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Fiscal63CaseService
{
    public function __construct(
        private readonly Fiscal63Access $access,
        private readonly Fiscal63Policy $policy,
        private readonly Fiscal63Integrity $integrity,
        private readonly Fiscal62Integrity $fiscal,
    ) {}

    /**
     * Called only after monitoring has authorized
     * a human or system actor.
     */
    public function openDetected(
        int $ticketId,
        int $actor
    ): ?array {
        $this->enabled();

        if ($ticketId <= 0 || $actor <= 0) {
            throw new DomainException(
                'Invalid fiscal incident detection identity'
            );
        }

        return DB::transaction(function () use (
            $ticketId,
            $actor
        ): ?array {
            $orderId = DB::table(
                'checkout_fiscal_tickets62 as t'
            )
                ->join(
                    'checkout_fiscal_documents61 as d',
                    'd.id',
                    '=',
                    't.document_id'
                )
                ->where('t.id', $ticketId)
                ->value('d.order_id');

            if ($orderId === null) {
                throw new DomainException(
                    'Fiscal ticket source not found'
                );
            }

            DB::table('orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $row = DB::table(
                'checkout_fiscal_tickets62'
            )
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first();

            $ticket = $this->fiscal->ticket(
                $ticketId
            );

            $reason = $this->policy->reason(
                $row
            );

            if ($reason === null) {
                return null;
            }

            $existing = DB::table(
                'checkout_fiscal_cases63'
            )
                ->where('ticket_id', $ticketId)
                ->where('reason_code', $reason)
                ->first(['id']);

            if ($existing !== null) {
                return $this->integrity->read(
                    (int) $existing->id
                );
            }

            $events = $this->fiscal->events(
                $ticketId
            );

            if ($events === []) {
                throw new RuntimeException(
                    'Fiscal ticket has no signed events'
                );
            }

            $last = $events[
            array_key_last($events)
            ];

            $identity = [
                'ticket_id' => $ticketId,
                'reason_code' => $reason,
                'ticket_status_at_open' =>
                    $ticket['status'],
                'request_sha256' =>
                    $ticket['request_sha256'],
                'event_id_at_open' =>
                    $last['event_id'],
                'opened_by' => $actor,
            ];

            $id = (int) DB::table(
                'checkout_fiscal_cases63'
            )->insertGetId([
                ...$identity,
                'case_hmac' =>
                    $this->integrity->signature(
                        $identity
                    ),
                'created_at' => now(),
            ]);

            return $this->integrity->read($id);
        }, 3);
    }

    public function acknowledge(
        int $caseId
    ): array {
        $actor = $this->access->actor(
            Fiscal63Access::ACK
        );

        $this->enabled();

        return DB::transaction(function () use (
            $caseId,
            $actor
        ): array {
            $this->lockCase($caseId);

            $case = $this->integrity->read(
                $caseId
            );

            if ($case['acknowledged_by'] !== null) {
                if (
                    $case['acknowledged_by'] !== $actor
                ) {
                    throw new DomainException(
                        'Fiscal case already acknowledged by another reviewer'
                    );
                }

                return $case;
            }

            if ($actor === $case['opened_by']) {
                throw new DomainException(
                    'Incident opener cannot acknowledge own case'
                );
            }

            $this->integrity->appendAction(
                $caseId,
                'acknowledge',
                $actor
            );

            return $this->integrity->read(
                $caseId
            );
        }, 3);
    }

    public function addNote(
        int $caseId,
        string $note
    ): array {
        $actor = $this->access->actor(
            Fiscal63Access::NOTE
        );

        $this->enabled();

        $note = trim($note);

        if (
            mb_strlen($note) < 4
            || mb_strlen($note) > 500
        ) {
            throw new DomainException(
                'Fiscal case note must be 4..500 characters'
            );
        }

        return DB::transaction(function () use (
            $caseId,
            $note,
            $actor
        ): array {
            $this->lockCase($caseId);

            $case = $this->integrity->read(
                $caseId
            );

            if ($case['status'] === 'closed') {
                throw new DomainException(
                    'Closed fiscal case cannot be changed'
                );
            }

            $this->integrity->appendAction(
                $caseId,
                'note',
                $actor,
                $note
            );

            return $this->integrity->read(
                $caseId
            );
        }, 3);
    }

    public function requestPoll(
        int $caseId
    ): array {
        $actor = $this->access->actor(
            Fiscal63Access::POLL
        );

        $this->enabled();

        if (
            config('fiscal63.provider_poll_enabled')
            !== true
            || config('fiscal62.enabled') !== true
        ) {
            throw new DomainException(
                'Fiscal provider GET polling is disabled'
            );
        }

        $case = DB::transaction(function () use (
            $caseId,
            $actor
        ): array {
            $this->lockCase($caseId);

            $case = $this->integrity->read(
                $caseId
            );

            if (
                $case['status'] !== 'acknowledged'
                || $actor === $case['opened_by']
                || !in_array(
                    $case['ticket_status'],
                    [
                        'uncertain',
                        'provider_pending',
                        'needs_review',
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'Acknowledged unresolved ticket required for provider GET'
                );
            }

            $last = DB::table(
                'checkout_fiscal_case_actions63'
            )
                ->where('case_id', $caseId)
                ->where('action', 'poll_requested')
                ->orderByDesc('id')
                ->first(['created_at']);

            if (
                $last !== null
                && CarbonImmutable::parse(
                    (string) $last->created_at
                )->gt(
                    CarbonImmutable::now('UTC')
                        ->subSeconds(
                            (int) config(
                                'fiscal63.poll_cooldown_seconds',
                                120
                            )
                        )
                )
            ) {
                throw new DomainException(
                    'Fiscal provider GET cooldown active'
                );
            }

            $this->integrity->appendAction(
                $caseId,
                'poll_requested',
                $actor
            );

            return $this->integrity->read(
                $caseId
            );
        }, 3);

        Fiscal62PollJob::dispatch(
            $case['ticket_id'],
            $actor
        )
            ->onQueue('fiscal-poll62')
            ->afterCommit();

        return [
            'case' => $case,
            'poll_queued' => true,
            'http_post_performed' => false,
        ];
    }

    /**
     * Convert an old "transmitting" marker to
     * "uncertain". Never resend POST.
     */
    public function recoverTransmission(
        int $caseId
    ): array {
        $actor = $this->access->actor(
            Fiscal63Access::RECOVER
        );

        $this->enabled();

        return DB::transaction(function () use (
            $caseId,
            $actor
        ): array {
            $locator = $this->integrity->read(
                $caseId
            );

            $orderId = DB::table(
                'checkout_fiscal_tickets62 as t'
            )
                ->join(
                    'checkout_fiscal_documents61 as d',
                    'd.id',
                    '=',
                    't.document_id'
                )
                ->where(
                    't.id',
                    $locator['ticket_id']
                )
                ->value('d.order_id');

            if ($orderId === null) {
                throw new DomainException(
                    'Fiscal ticket order missing'
                );
            }

            DB::table('orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->lockCase($caseId);

            $case = $this->integrity->read(
                $caseId
            );

            $ticket = DB::table(
                'checkout_fiscal_tickets62'
            )
                ->where('id', $case['ticket_id'])
                ->lockForUpdate()
                ->first();

            $ticketIdentity = $this->fiscal->ticket(
                $case['ticket_id']
            );

            $postClaimedBy = [];

            foreach ($this->fiscal->events(
                $case['ticket_id']
            ) as $event) {
                if ($event['kind'] === 'post_claimed') {
                    $postClaimedBy[] =
                        (int) $event['actor_id'];
                }
            }

            if (
                $case['reason_code']
                !== 'stale_transmission'
                || $case['status'] !== 'acknowledged'
                || count($postClaimedBy) !== 1
                || in_array(
                    $actor,
                    [
                        $case['opened_by'],
                        $case['acknowledged_by'],
                        $ticketIdentity['prepared_by'],
                        $postClaimedBy[0],
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'Independent recovery requires one signed previous POST claim'
                );
            }

            if ($ticket->status === 'uncertain') {
                $hasRecovery = DB::table(
                    'checkout_fiscal_case_actions63'
                )
                    ->where('case_id', $caseId)
                    ->where('action', 'recover_uncertain')
                    ->exists();

                if ($hasRecovery) {
                    return $case;
                }
            }

            if (!$this->policy->transmissionIsStale(
                $ticket
            )) {
                throw new DomainException(
                    'Fiscal transmission is not stale or was already handled'
                );
            }

            DB::table(
                'checkout_fiscal_tickets62'
            )
                ->where(
                    'id',
                    $case['ticket_id']
                )
                ->update([
                    'status' => 'uncertain',
                    'updated_at' => now(),
                ]);

            $this->fiscal->event(
                $case['ticket_id'],
                'transmission_recovered',
                'Old POST attempt marked uncertain; never retransmit',
                $actor
            );

            $this->integrity->appendAction(
                $caseId,
                'recover_uncertain',
                $actor
            );

            return $this->integrity->read(
                $caseId
            );
        }, 3);
    }

    public function close(
        int $caseId
    ): array {
        $actor = $this->access->actor(
            Fiscal63Access::CLOSE
        );

        $this->enabled();

        return DB::transaction(function () use (
            $caseId,
            $actor
        ): array {
            $this->lockCase($caseId);

            $case = $this->integrity->read(
                $caseId
            );

            if ($case['status'] === 'closed') {
                return $case;
            }

            if (
                $case['status'] !== 'acknowledged'
                || in_array(
                    $actor,
                    [
                        $case['opened_by'],
                        $case['acknowledged_by'],
                    ],
                    true
                )
                || $case['ticket_status'] !== 'done'
                || $case['fiscal_code'] === null
                || !$case['provider_done_evidence']
            ) {
                throw new DomainException(
                    'Independent closure requires verified provider DONE'
                );
            }

            $this->integrity->appendAction(
                $caseId,
                'close',
                $actor
            );

            return $this->integrity->read(
                $caseId
            );
        }, 3);
    }

    public function show(
        int $caseId
    ): array {
        $this->access->actor(
            Fiscal63Access::VIEW
        );

        return $this->integrity->read(
            $caseId
        );
    }

    private function lockCase(
        int $caseId
    ): void {
        if (
            $caseId <= 0
            || DB::table('checkout_fiscal_cases63')
                ->where('id', $caseId)
                ->lockForUpdate()
                ->first(['id']) === null
        ) {
            throw new DomainException(
                'Fiscal triage case not found'
            );
        }
    }

    private function enabled(): void
    {
        if (config('fiscal63.enabled') !== true) {
            throw new DomainException(
                'Fiscal63 incident management disabled'
            );
        }
    }
}
