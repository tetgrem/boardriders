<?php

declare(strict_types=1);

namespace App\Services\Warehouse72;

use App\Services\Inventory\InventoryService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Warehouse72Documents
{
    public function __construct(
        private readonly Warehouse72Audit $audit,
        private readonly InventoryService $inventory
    ) {}

    public function submit(array $data): array
    {
        $actor = $this->audit->actor(true);

        $key = strtolower($data['operation_key']);
        $kind = $data['kind'];

        unset($data['operation_key']);

        return DB::transaction(function () use (
            $actor, $key, $kind, $data
        ): array {
            // Serialize administrative warehouse documents.
            // Existing inventory services retain their own row locks.
            DB::select('SELECT pg_advisory_xact_lock(720072)');

            $existing = DB::table('checkout_warehouse_documents72')
                ->where('operation_key', $key)
                ->first();

            if ($existing !== null) {
                $event = $this->audit->verify($existing);

                if ($event['actor_id'] !== $actor
                    || $event['kind'] !== $kind
                    || $event['request_json']
                    !== $this->audit->canonical($data)) {
                    throw new DomainException(
                        'Ключ документа уже использован'
                    );
                }

                return [
                    'document_id' => $event['document_id'],
                    'kind' => $kind,
                    'result' => $event['result'],
                    'replayed' => true,
                ];
            }

            if (in_array($kind, [
                'warehouse_create',
                'warehouse_update',
            ], true)) {
                $result = $this->saveWarehouse($kind, $data);

                return $this->audit->append(
                    $key,
                    $actor,
                    $kind,
                    $result['warehouse_id'],
                    null,
                    $data,
                    $result
                );
            }

            $warehouseId = (int) $data['warehouse_id'];
            $reference = trim($data['reference']);
            $note = $data['note'] ?? null;

            $this->assertOwnedWarehouse($warehouseId);

            if (DB::table('checkout_warehouse_documents72')
                ->where('kind', $kind)
                ->where('warehouse_id', $warehouseId)
                ->where('reference', $reference)
                ->exists()) {
                throw new DomainException(
                    'Документ с таким номером уже проведён на этом складе'
                );
            }

            $targetId = null;

            if ($kind === 'transfer') {
                $targetId = (int) $data['to_warehouse_id'];

                if ($targetId === $warehouseId) {
                    throw new DomainException(
                        'Выберите другой склад назначения'
                    );
                }

                $this->assertOwnedWarehouse($targetId);
            }

            $lines = $data['lines'];

            if ($lines === []
                || count($lines) >
                (int) config('warehouse72.max_document_lines', 50)) {
                throw new DomainException(
                    'Недопустимое количество строк документа'
                );
            }

            $variantIds = array_map(
                static fn (array $line): int => (int) $line['variant_id'],
                $lines
            );

            if (count(array_unique($variantIds)) !== count($variantIds)) {
                throw new DomainException(
                    'Один SKU может быть указан только один раз'
                );
            }

            if (DB::table('product_variants')
                    ->whereIn('id', $variantIds)
                    ->count() !== count($variantIds)) {
                throw new DomainException(
                    'Один из вариантов товара не существует'
                );
            }

            usort(
                $lines,
                static fn (array $a, array $b): int =>
                    (int) $a['variant_id'] <=> (int) $b['variant_id']
            );

            $results = [];

            foreach ($lines as $line) {
                $variantId = (int) $line['variant_id'];

                // Every underlying operation receives its own UUID.
                $lineKey = (string) Str::uuid();

                $result = match ($kind) {
                    'receipt' => $this->receive(
                        $warehouseId,
                        $variantId,
                        (int) $line['quantity'],
                        $reference,
                        $lineKey,
                        $note
                    ),

                    'transfer' => [
                        'transfer_id' => $this->inventory->transfer(
                            $warehouseId,
                            $targetId,
                            $variantId,
                            (int) $line['quantity'],
                            $reference,
                            $lineKey,
                            $note
                        ),
                        'quantity' => (int) $line['quantity'],
                    ],

                    'stocktake' => [
                        'adjustment_id' =>
                            $this->inventory->reconcileStockCount(
                                $warehouseId,
                                $variantId,
                                (int) $line['quantity'],
                                $reference,
                                $lineKey,
                                (int) $line['expected_revision'],
                                'stocktake',
                                $note
                            ),
                        'counted_qty' => (int) $line['quantity'],
                    ],

                    'writeoff' => $this->writeoff(
                        $warehouseId,
                        $variantId,
                        $line,
                        $reference,
                        $lineKey,
                        $data['reason'],
                        $note
                    ),

                    default => throw new DomainException(
                        'Неизвестный тип складского документа'
                    ),
                };

                $results[] = [
                    'variant_id' => $variantId,
                    'operation_key' => $lineKey,
                    ...$result,
                ];
            }

            return $this->audit->append(
                $key,
                $actor,
                $kind,
                $warehouseId,
                $reference,
                $data,
                [
                    'warehouse_id' => $warehouseId,
                    'to_warehouse_id' => $targetId,
                    'reference' => $reference,
                    'line_count' => count($results),
                    'lines' => $results,
                ]
            );
        }, 3);
    }

    private function receive(
        int $warehouseId,
        int $variantId,
        int $quantity,
        string $reference,
        string $key,
        ?string $note
    ): array {
        $stock = $this->inventory->receiveOnce(
            $warehouseId,
            $variantId,
            $quantity,
            $reference,
            $key,
            $note
        );

        $receiptId = DB::table('inventory_receipts')
            ->where('receipt_key', $key)
            ->value('id');

        return [
            'receipt_id' => (int) $receiptId,
            'stock_id' => (int) $stock->id,
            'quantity' => $quantity,
        ];
    }

    private function writeoff(
        int $warehouseId,
        int $variantId,
        array $line,
        string $reference,
        string $key,
        string $reason,
        ?string $note
    ): array {
        $stock = DB::table('inventory_stocks')
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if ($stock === null) {
            throw new DomainException(
                'Невозможно списать отсутствующий товар'
            );
        }

        if ((int) $stock->revision
            !== (int) $line['expected_revision']) {
            throw new DomainException(
                'Остаток изменился. Обновите данные и повторите подсчёт'
            );
        }

        $quantity = (int) $line['quantity'];

        $available = (int) $stock->on_hand_qty
            - (int) $stock->reserved_qty;

        if ($quantity < 1 || $quantity > $available) {
            throw new DomainException(
                'Недостаточно свободного товара для списания'
            );
        }

        $counted = (int) $stock->on_hand_qty - $quantity;

        $adjustmentId = $this->inventory->reconcileStockCount(
            $warehouseId,
            $variantId,
            $counted,
            $reference,
            $key,
            (int) $line['expected_revision'],
            $reason,
            $note
        );

        return [
            'adjustment_id' => $adjustmentId,
            'quantity' => $quantity,
            'counted_qty' => $counted,
        ];
    }

    private function assertOwnedWarehouse(int $id): void
    {
        if (!DB::table('warehouses')
            ->where('id', $id)
            ->where('kind', 'owned')
            ->where('is_active', true)
            ->exists()) {
            throw new DomainException(
                'Операции разрешены только на активных собственных складах'
            );
        }
    }

    private function saveWarehouse(
        string $kind,
        array $data
    ): array {
        $values = [
            'name' => trim($data['name']),
            'country_code' => strtoupper($data['country_code']),
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'updated_at' => now(),
        ];

        if ($kind === 'warehouse_create') {
            $id = (int) DB::table('warehouses')->insertGetId([
                ...$values,
                'code' => 'WH-' . strtoupper((string) Str::uuid()),
                'kind' => 'owned',
                'is_active' => true,
                'admin_revision' => 1,
                'created_at' => now(),
            ]);

            return [
                'warehouse_id' => $id,
                'revision' => 1,
                'code' => DB::table('warehouses')
                    ->where('id', $id)->value('code'),
            ];
        }

        $id = (int) $data['warehouse_id'];

        $warehouse = DB::table('warehouses')
            ->where('id', $id)
            ->lockForUpdate()
            ->first();

        if ($warehouse === null) {
            abort(404, 'Warehouse not found');
        }

        if ($warehouse->kind !== 'owned') {
            throw new DomainException(
                'Партнёрский склад доступен только для просмотра'
            );
        }

        $revision = (int) $warehouse->admin_revision;

        if ($revision !== (int) $data['expected_revision']
            || $revision >= 2147483646) {
            throw new DomainException(
                'Данные склада изменились. Обновите страницу'
            );
        }

        // A physical warehouse must keep its country once created.
        if ($warehouse->country_code !== $values['country_code']) {
            throw new DomainException(
                'Страну существующего склада менять нельзя'
            );
        }

        DB::table('warehouses')->where('id', $id)->update([
            ...$values,
            'admin_revision' => $revision + 1,
        ]);

        return [
            'warehouse_id' => $id,
            'revision' => $revision + 1,
            'code' => (string) $warehouse->code,
        ];
    }
}
