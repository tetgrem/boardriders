<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Fiscal62Integrity
{
    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer
    ) {}

    public function ticket(int $id): array
    {
        $row = DB::table('checkout_fiscal_tickets62')
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw new DomainException(
                'Fiscal62CheckboxClient.php ticket missing'
            );
        }

        $identity = [
            'document_id' => (int) $row->document_id,
            'provider_receipt_id' =>
                (string) $row->provider_receipt_id,
            'related_receipt_id' =>
                $row->related_receipt_id === null
                    ? null
                    : (string) $row->related_receipt_id,
            'request_sha256' =>
                (string) $row->request_sha256,
            'prepared_by' => (int) $row->prepared_by,
        ];

        $this->signed(
            (string) $row->identity_hmac,
            $identity
        );

        $json = (string) $row->request_json;

        if (!hash_equals(
            $identity['request_sha256'],
            hash('sha256', $json)
        )) {
            throw new RuntimeException(
                'Fiscal62CheckboxClient.php request bytes changed'
            );
        }

        $body = json_decode(
            $json,
            true,
            64,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($body)
            || ($body['id'] ?? null)
            !== $identity['provider_receipt_id']
        ) {
            throw new RuntimeException(
                'Fiscal62CheckboxClient.php request receipt UUID mismatch'
            );
        }

        return [
            'ticket_id' => $id,
            ...$identity,
            'status' => (string) $row->status,
            'attempted' => $row->attempted_at !== null,
            'fiscal_code' => $row->fiscal_code === null
                ? null
                : (string) $row->fiscal_code,
            'request' => $body,
        ];
    }

    public function event(
        int $ticketId,
        string $kind,
        string $detail,
        int $actor
    ): void {
        if (
            $ticketId <= 0
            || $actor <= 0
            || !preg_match('/^[a-z_]{3,32}$/D', $kind)
            || strlen($detail) > 100
        ) {
            throw new DomainException(
                'Invalid fiscal event'
            );
        }

        $identity = [
            'ticket_id' => $ticketId,
            'kind' => $kind,
            'detail' => $detail,
            'actor_id' => $actor,
        ];

        DB::table('checkout_fiscal_events62')->insert([
            ...$identity,
            'event_hmac' =>
                $this->signer->digest($identity),
            'created_at' => now(),
        ]);
    }

    public function events(int $ticketId): array
    {
        $events = DB::table('checkout_fiscal_events62')
            ->where('ticket_id', $ticketId)
            ->orderBy('id')
            ->get();

        $output = [];

        foreach ($events as $event) {
            $identity = [
                'ticket_id' => (int) $event->ticket_id,
                'kind' => (string) $event->kind,
                'detail' => (string) $event->detail,
                'actor_id' => (int) $event->actor_id,
            ];

            $this->signed(
                (string) $event->event_hmac,
                $identity
            );

            $output[] = [
                'event_id' => (int) $event->id,
                ...$identity,
            ];
        }

        return $output;
    }

    public function signed(
        string $stored,
        array $data
    ): void {
        if (!hash_equals(
            $stored,
            $this->signer->digest($data)
        )) {
            throw new RuntimeException(
                'Fiscal62CheckboxClient.php audit HMAC mismatch'
            );
        }
    }
}
