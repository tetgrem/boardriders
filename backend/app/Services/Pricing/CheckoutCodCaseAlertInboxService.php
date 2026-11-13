<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Authorized read and acknowledgement of internal CRM alerts. */
final class CheckoutCodCaseAlertInboxService
{
    public const TRIAGE_ABILITY =
        'cod.operations.alerts.triage';

    /** @return array<string,mixed> */
    public function listing(
        string $scope = 'mine',
        ?int $beforeId = null,
        int $limit = 20
    ): array {
        $actor = $this->actor($scope === 'team');

        if (
            !in_array($scope, ['mine', 'team'], true)
            || ($beforeId !== null && $beforeId < 1)
            || $limit < 1
            || $limit > 100
        ) {
            throw new InvalidArgumentException(
                'Invalid COD inbox filters'
            );
        }

        $q = DB::table('checkout_cod_case_alert_inbox as n')
            ->join(
                'checkout_cod_operations_cases as c',
                'c.id',
                '=',
                'n.case_id'
            );

        if ($scope === 'mine') {
            $q->where('n.recipient_id', $actor);
        } else {
            $q->whereNull('n.recipient_id');
        }

        if ($beforeId !== null) {
            $q->where('n.id', '<', $beforeId);
        }

        $rows = $q
            ->orderByDesc('n.id')
            ->limit($limit + 1)
            ->get([
                'n.id',
                'n.alert_kind',
                'n.recipient_id',
                'n.created_at',
                'n.read_at',
                'n.read_by',
                'c.case_key',
                'c.case_status',
            ]);

        $more = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $r): array => [
                'notification_id' => (int) $r->id,
                'case_key' => (string) $r->case_key,
                'case_status' => (string) $r->case_status,
                'kind' => (string) $r->alert_kind,
                'recipient_id' => $r->recipient_id === null
                    ? null
                    : (int) $r->recipient_id,
                'created_at' => (string) $r->created_at,
                'read_at' => $r->read_at === null
                    ? null
                    : (string) $r->read_at,
                'read_by' => $r->read_by === null
                    ? null
                    : (int) $r->read_by,
            ])
            ->values()
            ->all();

        return [
            'scope' => $scope,
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['notification_id']
                : null,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function acknowledge(int $notificationId): array
    {
        $actor = $this->actor(false);

        if ($notificationId < 1) {
            throw new InvalidArgumentException(
                'Invalid COD notification ID'
            );
        }

        return DB::transaction(function () use (
            $notificationId,
            $actor
        ): array {
            $n = DB::table('checkout_cod_case_alert_inbox')
                ->where('id', $notificationId)
                ->lockForUpdate()
                ->first();

            if ($n === null) {
                throw new DomainException(
                    'COD notification not found'
                );
            }

            if ($n->recipient_id === null) {
                $this->assertTriage();
            } elseif ((int) $n->recipient_id !== $actor) {
                throw new AuthorizationException(
                    'COD notification not assigned to current user'
                );
            }

            if ($n->read_at === null) {
                DB::table('checkout_cod_case_alert_inbox')
                    ->where('id', $notificationId)
                    ->update([
                        'read_at' => now(),
                        'read_by' => $actor,
                    ]);
            }

            $saved = DB::table(
                'checkout_cod_case_alert_inbox'
            )
                ->where('id', $notificationId)
                ->first();

            return [
                'notification_id' => (int) $saved->id,
                'read_at' => (string) $saved->read_at,
                'read_by' => (int) $saved->read_by,
                'already_acknowledged' => $n->read_at !== null,
                'payment_status_changed' => false,
            ];
        }, 3);
    }

    private function assertTriage(): void
    {
        if (!Gate::forUser(Auth::user())->allows(
            self::TRIAGE_ABILITY
        )) {
            throw new AuthorizationException(
                'COD team alert triage denied'
            );
        }
    }

    private function actor(bool $team): int
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
        ) {
            throw new AuthorizationException(
                'COD inbox permission denied'
            );
        }

        if ($team) {
            $this->assertTriage();
        }

        return (int) $id;
    }
}
