<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Crm64Audit
{
    public function __construct(private readonly CheckoutOrderReturnSigner $signer) {}

    public function viewed(int $orderId, int $actorId, string $operation): void
    {
        if ($orderId <= 0 || $actorId <= 0 || !in_array($operation, ['card','timeline','finance'], true)) {
            throw new DomainException('Invalid CRM access event');
        }
        $entry = ['order_id' => $orderId, 'actor_id' => $actorId, 'operation' => $operation];
        DB::table('checkout_crm_access64')->insert([
            ...$entry, 'event_hmac' => $this->signer->digest($entry), 'created_at' => now(),
        ]);
    }

    public function accessHistory(int $orderId, int $limit = 30): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('Invalid CRM audit limit');
        }
        return DB::table('checkout_crm_access64')->where('order_id', $orderId)
            ->orderByDesc('id')->limit($limit)->get()->map(function (object $row): array {
                $signed = [
                    'order_id' => (int) $row->order_id,
                    'actor_id' => (int) $row->actor_id,
                    'operation' => (string) $row->operation,
                ];
                if (!hash_equals((string) $row->event_hmac, $this->signer->digest($signed))) {
                    throw new RuntimeException('CRM access audit signature mismatch');
                }
                return ['audit_id' => (int) $row->id, ...$signed, 'created_at' => (string) $row->created_at];
            })->all();
    }
}
