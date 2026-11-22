<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use RuntimeException;

final class Crm64Notes
{
    public function __construct(
        private readonly Crm64Access $access,
        private readonly CheckoutOrderReturnSigner $signer,
    ) {}

    public function create(string $orderKey, string $operationKey, string $category, string $body): array
    {
        $actor = $this->access->actor(Crm64Access::NOTES);
        if (!Str::isUuid($orderKey) || !Str::isUuid($operationKey)) {
            throw new DomainException('Invalid CRM note UUID');
        }
        $operationKey = strtolower($operationKey);
        $body = trim($body);
        if (!in_array($category, ['service','finance','delivery','return','fiscal'], true)
            || mb_strlen($body) < 4 || mb_strlen($body) > 1000) {
            throw new DomainException('Invalid CRM note category or content');
        }
        if (($category === 'finance' && !$this->access->has(Crm64Access::FINANCE))
            || ($category === 'fiscal' && !$this->access->has(Crm64Access::FISCAL))
            || ($category === 'return' && !$this->access->has(\App\Services\Pricing\CheckoutOrderReturnService::VIEW_ABILITY))) {
            throw new AuthorizationException('CRM note category access denied');
        }
        return DB::transaction(function () use ($orderKey, $operationKey, $category, $body, $actor): array {
            $order = DB::table('orders')->where('order_key', strtolower($orderKey))
                ->lockForUpdate()->first(['id']);
            if ($order === null) {
                throw new DomainException('CRM order not found');
            }
            $data = [
                'order_id' => (int) $order->id,
                'operation_key' => $operationKey,
                'category' => $category,
                'body' => $body,
                'written_by' => $actor,
            ];
            $existing = DB::table('checkout_crm_notes64')->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                $saved = $this->verify($existing);
                foreach ($data as $key => $value) {
                    if ($saved[$key] !== $value) {
                        throw new DomainException('CRM note operation key reused with different content');
                    }
                }
                return $saved;
            }
            $id = (int) DB::table('checkout_crm_notes64')->insertGetId([
                ...$data, 'note_hmac' => $this->signer->digest($data), 'created_at' => now(),
            ]);
            return $this->verify(DB::table('checkout_crm_notes64')->where('id', $id)->first());
        }, 3);
    }

    public function history(int $orderId, int $limit = 50): array
    {
        $this->access->actor(Crm64Access::VIEW);
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('Invalid CRM note history limit');
        }

        $excluded = [];
        if (!$this->access->has(Crm64Access::FINANCE)) $excluded[] = 'finance';
        if (!$this->access->has(Crm64Access::FISCAL)) $excluded[] = 'fiscal';
        if (!$this->access->has(\App\Services\Pricing\CheckoutOrderReturnService::VIEW_ABILITY)) $excluded[] = 'return';

        return DB::table('checkout_crm_notes64')
            ->where('order_id', $orderId)
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('category', $excluded))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->verify($row))
            ->all();
    }

    private function verify(object $row): array
    {
        $data = [
            'order_id' => (int) $row->order_id,
            'operation_key' => (string) $row->operation_key,
            'category' => (string) $row->category,
            'body' => (string) $row->body,
            'written_by' => (int) $row->written_by,
        ];
        if (!hash_equals((string) $row->note_hmac, $this->signer->digest($data))) {
            throw new RuntimeException('CRM note signature mismatch');
        }
        return ['note_id' => (int) $row->id, ...$data, 'created_at' => (string) $row->created_at];
    }
}
