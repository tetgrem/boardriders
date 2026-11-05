<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Append-only зберігання фінансового snapshot для ВЖЕ існуючого order.
 *
 * ВАЖЛИВО: quote має надходити виключно із серверного pricing engine.
 * Цей сервіс не створює замовлення, не бронює товари/бонуси та не
 * підтверджує оплату. Production checkout повинен викликати його у
 * спільній транзакції після повторної перевірки цін і резервів.
 */
final class OrderFinancialSnapshotService
{
    public function __construct(
        private readonly CheckoutFinancialSnapshotBuilder $builder,
    ) {
    }

    /**
     * Повторний виклик з ТИМ САМИМ snapshot є ідемпотентним.
     * Інший вміст для того самого order_id заборонений.
     *
     * @param array<string,mixed> $serverQuote
     * @return array<string,mixed>
     */
    public function store(int $orderId, array $serverQuote): array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $built = $this->builder->build($serverQuote);
        $snapshot = $built['snapshot'];

        try {
            $json = json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new DomainException('Invalid snapshot JSON', 0, $exception);
        }

        $sha256 = hash('sha256', $json);

        if (!hash_equals($built['sha256'], $sha256)) {
            throw new RuntimeException('Financial snapshot checksum mismatch');
        }

        return DB::transaction(function () use (
            $orderId,
            $snapshot,
            $json,
            $sha256
        ): array {
            // Серіалізуємо конкуруючі спроби створити snapshot
            // одного замовлення за допомогою блокування orders.
            $order = DB::table('orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('Order does not exist');
            }

            $existing = DB::table('order_financial_snapshots')
                ->where('order_id', $orderId)
                ->first();

            if ($existing !== null) {
                $saved = $this->decodeRow($existing);

                // Контроль обох значень: хешу та точних JSON-байтів.
                if (
                    !hash_equals($saved['sha256'], $sha256)
                    || $existing->snapshot_json !== $json
                ) {
                    throw new DomainException(
                        'Order already has a different financial snapshot'
                    );
                }

                return $saved;
            }

            // Новий snapshot дозволено лише до оплати.
            if ($order->payment_status !== 'pending') {
                throw new DomainException(
                    'Cannot attach financial snapshot to non-pending order'
                );
            }

            $id = DB::table('order_financial_snapshots')->insertGetId([
                'order_id' => $orderId,
                'schema_version' => $snapshot['schema_version'],
                'currency_code' => $snapshot['currency_code'],
                'goods_payable_minor' => $snapshot['goods_payable_minor'],
                'snapshot_json' => $json,
                'snapshot_sha256' => $sha256,
            ]);

            $row = DB::table('order_financial_snapshots')
                ->where('id', $id)
                ->first();

            return $this->decodeRow($row);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('order_financial_snapshots')
            ->where('order_id', $orderId)
            ->first();

        return $row === null ? null : $this->decodeRow($row);
    }

    /** @return array<string,mixed> */
    private function decodeRow(object $row): array
    {
        $json = (string) $row->snapshot_json;
        $actualHash = hash('sha256', $json);
        $storedHash = (string) $row->snapshot_sha256;

        if (!hash_equals($storedHash, $actualHash)) {
            throw new RuntimeException('Stored financial snapshot checksum mismatch');
        }

        try {
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored financial snapshot has invalid JSON', 0, $exception);
        }

        if (
            !is_array($snapshot)
            || ($snapshot['schema_version'] ?? null) !== (int) $row->schema_version
            || ($snapshot['currency_code'] ?? null) !== $row->currency_code
            || ($snapshot['goods_payable_minor'] ?? null) !== (int) $row->goods_payable_minor
        ) {
            throw new RuntimeException('Stored financial snapshot metadata mismatch');
        }

        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'sha256' => $storedHash,
            'currency_code' => (string) $row->currency_code,
            'goods_payable_minor' => (int) $row->goods_payable_minor,
            'snapshot' => $snapshot,
            'created_at' => (string) $row->created_at,
        ];
    }
}
