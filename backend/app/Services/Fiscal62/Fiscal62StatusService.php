<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use App\Jobs\Fiscal62PollJob;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class Fiscal62StatusService
{
    public function __construct(
        private readonly Fiscal62Access $access,
        private readonly Fiscal62Integrity $audit,
        private readonly Fiscal62CheckboxClient $checkbox,
    ) {}

    public function queuePoll(int $ticketId): array
    {
        $actor = $this->access->actor(
            Fiscal62Access::POLL
        );

        $data = $this->audit->ticket(
            $ticketId
        );

        if ($data['status'] === 'done') {
            return $data;
        }

        if (!$data['attempted']) {
            throw new DomainException(
                'Fiscal POST has not been attempted'
            );
        }

        Fiscal62PollJob::dispatch(
            $ticketId,
            $actor
        )
            ->onQueue('fiscal-poll62')
            ->delay(now()->addSeconds(2))
            ->afterCommit();

        return [
            ...$data,
            'poll_queued' => true,
        ];
    }

    public function poll(
        int $ticketId,
        int $actor
    ): array {
        if (config('fiscal62.enabled') !== true) {
            throw new DomainException(
                'Fiscal62 disabled'
            );
        }

        if ($actor <= 0) {
            throw new DomainException(
                'Invalid fiscal reviewer'
            );
        }

        $ticket = $this->audit->ticket(
            $ticketId
        );

        if ($ticket['status'] === 'done') {
            return $ticket;
        }

        if (!$ticket['attempted']) {
            throw new DomainException(
                'Fiscal POST has not been attempted'
            );
        }

        if ($ticket['status'] === 'transmitting') {
            $this->recoverInterruptedPost(
                $ticketId,
                $actor
            );

            $ticket = $this->audit->ticket($ticketId);
        }

        try {
            $response = $this->checkbox->get(
                $ticket['provider_receipt_id']
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->audit->ticket(
                $ticketId
            );
        }

        $body = $response['body'];
        $httpStatus = (int) $response['http_status'];

        $status = 'needs_review';
        $code = null;
        $kind = 'provider_review';
        $detail = 'GET HTTP '
            . $httpStatus
            . ' requires review';

        if (
            $httpStatus === 200
            && is_array($body)
            && ($body['id'] ?? null)
            === $ticket['provider_receipt_id']
        ) {
            $providerStatus = $body['status'] ?? null;

            if ($providerStatus === 'DONE') {
                if (
                    !is_string($body['fiscal_code'] ?? null)
                    || trim($body['fiscal_code']) === ''
                    || ($body['is_sent_dps'] ?? null) !== true
                    || ($body['total_payment'] ?? null)
                    !== $ticket['request']['payments'][0]['value']
                    || ($body['transaction']['response_status'] ?? null)
                    !== 'OK'
                ) {
                    $detail =
                        'GET DONE but fiscal evidence did not reconcile';
                } else {
                    $status = 'done';
                    $code = (string) $body['fiscal_code'];
                    $kind = 'provider_done';
                    $detail = 'DPS-confirmed Checkbox fiscal receipt';
                }
            } elseif (in_array(
                $providerStatus,
                ['CREATED', 'PENDING', 'SIGNED', 'DELIVERED'],
                true
            )) {
                $status = $ticket['status'] === 'needs_review'
                    ? 'needs_review'
                    : 'provider_pending';

                $kind = 'provider_pending';

                $detail = 'GET returned '
                    . $providerStatus;
            } elseif ($providerStatus === 'ERROR') {
                $detail =
                    'Checkbox receipt ERROR; manual review required';
            }
        }

        return DB::transaction(function () use (
            $ticketId,
            $actor,
            $status,
            $code,
            $kind,
            $detail
        ): array {
            $row = DB::table(
                'checkout_fiscal_tickets62'
            )
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new DomainException(
                    'Fiscal ticket not found'
                );
            }

            if ($row->status === 'done') {
                return $this->audit->ticket(
                    $ticketId
                );
            }

            if (
                $row->attempted_at === null
                || $row->status === 'transmitting'
            ) {
                throw new DomainException(
                    'Fiscal POST still pending'
                );
            }

            // Do not clear an existing manual-review alarm
            // just because Checkbox now reports PENDING.
            $next = $row->status === 'needs_review'
            && $status === 'provider_pending'
                ? 'needs_review'
                : $status;

            DB::table('checkout_fiscal_tickets62')
                ->where('id', $ticketId)
                ->update([
                    'status' => $next,
                    'fiscal_code' =>
                        $next === 'done'
                            ? $code
                            : null,
                    'updated_at' => now(),
                ]);

            $this->audit->event(
                $ticketId,
                $kind,
                $detail,
                $actor
            );

            return $this->audit->ticket(
                $ticketId
            );
        }, 3);
    }
    /**
     * A crashed worker cannot issue another POST.
     * After timeout, allow only provider GET verification.
     */
    private function recoverInterruptedPost(
        int $ticketId,
        int $actor
    ): void {
        DB::transaction(function () use (
            $ticketId,
            $actor
        ): void {
            $row = DB::table('checkout_fiscal_tickets62')
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first();

            if (
                $row === null
                || $row->status !== 'transmitting'
            ) {
                return;
            }

            $elapsed = time()
                - strtotime((string) $row->attempted_at);

            if ($elapsed < 60) {
                throw new DomainException(
                    'Original fiscal POST may still be in flight'
                );
            }

            DB::table('checkout_fiscal_tickets62')
                ->where('id', $ticketId)
                ->update([
                    'status' => 'uncertain',
                    'updated_at' => now(),
                ]);

            $this->audit->event(
                $ticketId,
                'post_stale',
                'Interrupted POST; no resend allowed',
                $actor
            );
        }, 3);
    }
}
