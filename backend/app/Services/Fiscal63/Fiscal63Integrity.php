<?php

declare(strict_types=1);

namespace App\Services\Fiscal63;

use App\Services\Fiscal62\Fiscal62Integrity;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Fiscal63Integrity
{
    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly Fiscal62Integrity $fiscal,
    ) {}

    public function read(int $caseId): array
    {
        if ($caseId <= 0) {
            throw new DomainException(
                'Invalid fiscal case ID'
            );
        }

        $row = DB::table('checkout_fiscal_cases63')
            ->where('id', $caseId)
            ->first();

        if ($row === null) {
            throw new DomainException(
                'Fiscal triage case not found'
            );
        }

        $identity = [
            'ticket_id' => (int) $row->ticket_id,
            'reason_code' => (string) $row->reason_code,
            'ticket_status_at_open' =>
                (string) $row->ticket_status_at_open,
            'request_sha256' =>
                (string) $row->request_sha256,
            'event_id_at_open' =>
                (int) $row->event_id_at_open,
            'opened_by' => (int) $row->opened_by,
        ];

        $this->signed(
            (string) $row->case_hmac,
            $identity
        );

        $ticket = $this->fiscal->ticket(
            $identity['ticket_id']
        );

        if (!hash_equals(
            $ticket['request_sha256'],
            $identity['request_sha256']
        )) {
            throw new RuntimeException(
                'Fiscal case source request identity changed'
            );
        }

        $fiscalEvents = $this->fiscal->events(
            $identity['ticket_id']
        );

        $eventIds = array_column(
            $fiscalEvents,
            'event_id'
        );

        if (!in_array(
            $identity['event_id_at_open'],
            $eventIds,
            true
        )) {
            throw new RuntimeException(
                'Fiscal case source event is missing'
            );
        }

        $rows = DB::table(
            'checkout_fiscal_case_actions63'
        )
            ->where('case_id', $caseId)
            ->orderBy('id')
            ->get();

        $actions = [];
        $acknowledgedBy = null;
        $closedBy = null;

        foreach ($rows as $action) {
            $data = [
                'case_id' => (int) $action->case_id,
                'action' => (string) $action->action,
                'actor_id' => (int) $action->actor_id,
                'note' => $action->note === null
                    ? null
                    : (string) $action->note,
            ];

            $this->signed(
                (string) $action->action_hmac,
                $data
            );

            if (
                $data['case_id'] !== $caseId
                || $closedBy !== null
            ) {
                throw new RuntimeException(
                    'Fiscal case action sequence invalid'
                );
            }

            if ($data['action'] === 'acknowledge') {
                if (
                    $acknowledgedBy !== null
                    || $data['actor_id']
                    === $identity['opened_by']
                ) {
                    throw new RuntimeException(
                        'Fiscal acknowledgement violates separation of duties'
                    );
                }

                $acknowledgedBy = $data['actor_id'];

            } elseif ($data['action'] === 'close') {
                if (
                    $acknowledgedBy === null
                    || in_array(
                        $data['actor_id'],
                        [
                            $identity['opened_by'],
                            $acknowledgedBy,
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Fiscal closure violates separation of duties'
                    );
                }

                $closedBy = $data['actor_id'];

            } elseif (in_array(
                $data['action'],
                ['poll_requested', 'recover_uncertain'],
                true
            )) {
                if (
                    $acknowledgedBy === null
                    || $data['actor_id']
                    === $identity['opened_by']
                ) {
                    throw new RuntimeException(
                        'Fiscal action requires independent acknowledgement'
                    );
                }

            } elseif ($data['action'] !== 'note') {
                throw new RuntimeException(
                    'Unknown fiscal case audit action'
                );
            }

            $actions[] = [
                'action_id' => (int) $action->id,
                ...$data,
                'created_at' =>
                    (string) $action->created_at,
            ];
        }

        $providerDone = false;

        foreach ($fiscalEvents as $event) {
            if ($event['kind'] === 'provider_done') {
                $providerDone = true;
            }
        }

        if (
            $closedBy !== null
            && (
                $ticket['status'] !== 'done'
                || $ticket['fiscal_code'] === null
                || !$providerDone
            )
        ) {
            throw new RuntimeException(
                'Closed fiscal case lacks provider DONE evidence'
            );
        }

        return [
            'case_id' => $caseId,
            ...$identity,
            'status' => $closedBy !== null
                ? 'closed'
                : (
                $acknowledgedBy !== null
                    ? 'acknowledged'
                    : 'open'
                ),
            'acknowledged_by' => $acknowledgedBy,
            'closed_by' => $closedBy,
            'ticket_status' => $ticket['status'],
            'provider_receipt_id' =>
                $ticket['provider_receipt_id'],
            'provider_done_evidence' => $providerDone,
            'fiscal_code' => $ticket['fiscal_code'],
            'actions' => $actions,
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * Internal database append only.
     * Call after an authorized case transition.
     */
    public function appendAction(
        int $caseId,
        string $action,
        int $actor,
        ?string $note = null
    ): void {
        if (
            $caseId <= 0
            || $actor <= 0
            || !in_array(
                $action,
                [
                    'acknowledge',
                    'note',
                    'poll_requested',
                    'recover_uncertain',
                    'close',
                ],
                true
            )
        ) {
            throw new DomainException(
                'Invalid fiscal action identity'
            );
        }

        $data = [
            'case_id' => $caseId,
            'action' => $action,
            'actor_id' => $actor,
            'note' => $note,
        ];

        DB::table(
            'checkout_fiscal_case_actions63'
        )->insert([
            ...$data,
            'action_hmac' =>
                $this->signer->digest($data),
            'created_at' => now(),
        ]);
    }

    public function signature(array $data): string
    {
        return $this->signer->digest($data);
    }

    private function signed(
        string $hmac,
        array $data
    ): void {
        if (!hash_equals(
            $hmac,
            $this->signer->digest($data)
        )) {
            throw new RuntimeException(
                'Fiscal63 immutable audit HMAC mismatch'
            );
        }
    }
}
