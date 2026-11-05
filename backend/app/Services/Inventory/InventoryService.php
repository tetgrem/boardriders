<?php

namespace App\Services\Inventory;

use App\Models\InventoryReservation;
use Illuminate\Support\Str;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryService
{
    /*
    |--------------------------------------------------------------------------
    | Надходження товару
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Резервування товару
    |--------------------------------------------------------------------------
    */



    /*
    |--------------------------------------------------------------------------
    | Зняття резерву
    |--------------------------------------------------------------------------
    */



    /*
    |--------------------------------------------------------------------------
    | Відвантаження зарезервованого товару
    |--------------------------------------------------------------------------
    */



    /*
    |--------------------------------------------------------------------------
    | Блокування складського залишку
    |--------------------------------------------------------------------------
    */

    private function lockStock(
        int $warehouseId,
        int $variantId,
        bool $create = false
    ): ?InventoryStock {
        if ($create) {
            $now = now();

            DB::table('inventory_stocks')->insertOrIgnore([
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'on_hand_qty' => 0,
                'reserved_qty' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Запис фізичного руху товару
    |--------------------------------------------------------------------------
    */

    private function recordMovement(
        InventoryStock $stock,
        string $type,
        int $quantityDelta,
        string $reference,
        ?string $note = null
    ): void {
        InventoryMovement::create([
            'inventory_stock_id' => $stock->id,
            'type' => $type,
            'quantity_delta' => $quantityDelta,
            'reference' => $reference,
            'note' => $note,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Перевірки вхідних даних
    |--------------------------------------------------------------------------
    */


    /*
    |--------------------------------------------------------------------------
    | Резервування під замовлення
    |--------------------------------------------------------------------------
    | Повторний запит з тим самим reservation_key
    | не створює додатковий резерв.
    */

    public function reserveForOrder(
        int $warehouseId,
        int $variantId,
        int $quantity,
        string $reference,
        string $reservationKey,
        ?\DateTimeInterface $expiresAt = null
    ): InventoryReservation {
        $this->validateQuantity($quantity);
        $this->validateReference($reference);

        if ($warehouseId <= 0 || $variantId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний склад або SKU.'
            );
        }

        if (!Str::isUuid($reservationKey)) {
            throw new InvalidArgumentException(
                'Некоректний UUID резервування.'
            );
        }

        $reservationKey = strtolower($reservationKey);
        $reference = trim($reference);

        return DB::transaction(function () use (
            $warehouseId,
            $variantId,
            $quantity,
            $reference,
            $reservationKey,
            $expiresAt
        ) {
            /*
             * Блокування за ключем операції.
             * Працює в PostgreSQL.
             *
             * Два запити з однаковим UUID
             * не обробляються одночасно.
             */
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$reservationKey]
            );

            /*
             * Перевіряємо, чи вже існує резерв
             * з таким ключем.
             */
            $existing = InventoryReservation::query()
                ->with('stock')
                ->where('reservation_key', $reservationKey)
                ->first();

            if ($existing) {
                $sameRequest =
                    $existing->reference === $reference
                    && $existing->quantity === $quantity
                    && (int) $existing->stock->warehouse_id === $warehouseId
                    && (int) $existing->stock->variant_id === $variantId
                    && $existing->expires_at?->getTimestamp()
                    === $expiresAt?->getTimestamp();

                if (!$sameRequest) {
                    throw new InvalidArgumentException(
                        'Ключ резервування вже використано '
                        . 'для іншої операції.'
                    );
                }

                return $existing;
            }


            if (
                $expiresAt !== null
                && $expiresAt->getTimestamp() <= now()->getTimestamp()
            ) {
                throw new InvalidArgumentException(
                    'Термін резервування має бути в майбутньому.'
                );
            }


            /*
             * Блокуємо складський залишок.
             * Поки триває транзакція, інша
             * операція не зможе змінити цей рядок.
             */
            $stock = $this->lockStock(
                $warehouseId,
                $variantId
            );

            if (
                !$stock ||
                $stock->available_qty < $quantity
            ) {
                throw new DomainException(
                    'Недостатньо товару для резервування.'
                );
            }

            /*
             * Збільшуємо загальний резерв.
             */
            $stock->reserved_qty += $quantity;
            $stock->save();

            /*
             * Створюємо окремий запис резервування.
             */
            return InventoryReservation::create([
                'reservation_key' => $reservationKey,
                'inventory_stock_id' => $stock->id,
                'reference' => $reference,
                'quantity' => $quantity,
                'shipped_qty' => 0,
                'released_qty' => 0,
                'expires_at' => $expiresAt,
            ]);
        }, 3);
    }


    /*
    |--------------------------------------------------------------------------
    | Відвантаження конкретного резервування
    |--------------------------------------------------------------------------
    */

    public function shipOrderReservation(
        string $reservationKey,
        int $quantity,
        string $operationKey
    ): InventoryReservation {
        return $this->processReservationOperation(
            $reservationKey,
            $quantity,
            $operationKey,
            'ship'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Скасування частини або всього резервування
    |--------------------------------------------------------------------------
    */

    public function releaseOrderReservation(
        string $reservationKey,
        int $quantity,
        string $operationKey
    ): InventoryReservation {
        return $this->processReservationOperation(
            $reservationKey,
            $quantity,
            $operationKey,
            'release'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Єдина точка обробки операцій резервування
    |--------------------------------------------------------------------------
    */

    private function processReservationOperation(
        string $reservationKey,
        int $quantity,
        string $operationKey,
        string $type
    ): InventoryReservation {
        $this->validateQuantity($quantity);

        if (
            !Str::isUuid($reservationKey) ||
            !Str::isUuid($operationKey)
        ) {
            throw new InvalidArgumentException(
                'Некоректний UUID операції.'
            );
        }

        $reservationKey = strtolower($reservationKey);
        $operationKey = strtolower($operationKey);

        if (!in_array($type, ['ship', 'release'], true)) {
            throw new InvalidArgumentException(
                'Невідомий тип операції.'
            );
        }

        return DB::transaction(function () use (
            $reservationKey,
            $quantity,
            $operationKey,
            $type
        ) {
            /*
             * Захист від одночасної обробки
             * однакового ключа операції.
             */
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['operation:' . $operationKey]
            );

            // Перевірка повторного запиту.
            $existing = DB::table('inventory_reservation_operations')
                ->where('operation_key', $operationKey)
                ->first();

            if ($existing) {
                $reservation = InventoryReservation::query()
                    ->findOrFail($existing->inventory_reservation_id);

                if (
                    $reservation->reservation_key !== $reservationKey ||
                    $existing->type !== $type ||
                    (int) $existing->quantity !== $quantity
                ) {
                    throw new InvalidArgumentException(
                        'Ключ операції вже використано '
                        . 'з іншими параметрами.'
                    );
                }

                return $reservation->refresh();
            }

            /*
             * Для пов'язаних із замовленням резервів дотримуємося
             * єдиного порядку блокування: order -> reservation -> stock.
             * Інакше оплата, закінчення терміну та ручне звільнення
             * можуть створити взаємне блокування або гонку.
             */
            $orderId = DB::table('inventory_reservations')
                ->where('reservation_key', $reservationKey)
                ->value('order_id');

            $order = null;

            if ($orderId !== null) {
                $order = DB::table('orders')
                    ->where('id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if ($order === null) {
                    throw new DomainException(
                        'Замовлення для резерву не знайдено.'
                    );
                }
            }

            $reservation = InventoryReservation::query()
                ->where('reservation_key', $reservationKey)
                ->lockForUpdate()
                ->firstOrFail();

            // Резерв могли прив'язати до замовлення після першого SELECT.
            // Не продовжуємо операцію без коректного блокування замовлення.
            $actualOrderId = $reservation->order_id === null
                ? null
                : (int) $reservation->order_id;

            if ($actualOrderId !== ($orderId === null ? null : (int) $orderId)) {
                throw new DomainException(
                    'Резервування змінено під час операції. Повторіть запит.'
                );
            }

            // Не можна вручну звільнити товар оплаченого замовлення.
            if (
                $order !== null
                && $type === 'release'
                && $order->payment_status !== 'pending'
            ) {
                throw new DomainException(
                    'Резерв оплаченого або проблемного замовлення звільняти не можна.'
                );
            }

            // Не відвантажуємо замовлення до підтвердженої оплати.
            if (
                $order !== null
                && $type === 'ship'
                && $order->payment_status !== 'paid'
            ) {
                throw new DomainException(
                    'Відвантажувати неоплачене або проблемне замовлення не можна.'
                );
            }

            /*
             * Блокуємо складський залишок.
             */
            $stock = InventoryStock::query()
                ->whereKey($reservation->inventory_stock_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->active_qty < $quantity) {
                throw new DomainException(
                    'Недостатньо товару в цьому резервуванні.'
                );
            }

            if ($stock->reserved_qty < $quantity) {
                throw new DomainException(
                    'Складський резерв не відповідає операції.'
                );
            }

            // Обидві операції зменшують резерв.
            $stock->reserved_qty -= $quantity;

            if ($type === 'ship') {
                if ($stock->on_hand_qty < $quantity) {
                    throw new DomainException(
                        'Недостатньо фізичного залишку.'
                    );
                }

                // Відвантаження зменшує фізичний залишок.
                $stock->on_hand_qty -= $quantity;
                $reservation->shipped_qty += $quantity;
            } else {
                // Скасування не змінює фізичний залишок.
                $reservation->released_qty += $quantity;
            }

            $stock->save();
            $reservation->save();

            // Журнал операцій із захистом від дублікатів.
            DB::table('inventory_reservation_operations')->insert([
                'operation_key' => $operationKey,
                'inventory_reservation_id' => $reservation->id,
                'type' => $type,
                'quantity' => $quantity,
                'created_at' => now(),
            ]);

            // Фізичний рух фіксуємо лише при відвантаженні.
            if ($type === 'ship') {
                $this->recordMovement(
                    $stock,
                    'sale',
                    -$quantity,
                    'SHIP-' . $operationKey,
                    $reservation->reference
                );
            }

            return $reservation->refresh();
        }, 3);
    }



    /**
     * Звільняє активну кількість простроченого резерву.
     *
     * Повертає true, якщо резерв було звільнено.
     * Повертає false, якщо змінювати нічого не потрібно.
     */
    public function expireOrderReservation(
        string $reservationKey
    ): bool {
        if (!Str::isUuid($reservationKey)) {
            throw new InvalidArgumentException(
                'Некоректний UUID резервування.'
            );
        }

        $reservationKey = strtolower($reservationKey);

        return DB::transaction(function () use ($reservationKey): bool {
            // Read the order pointer before locks to maintain order ->
            // reservation -> stock lock order across payment and expiration.
            $orderId = DB::table('inventory_reservations')
                ->where('reservation_key', $reservationKey)
                ->value('order_id');

            $order = null;

            if ($orderId !== null) {
                $order = DB::table('orders')
                    ->where('id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if ($order === null) {
                    throw new DomainException('Замовлення для резерву не знайдено.');
                }
            }

            $reservation = InventoryReservation::query()
                ->where('reservation_key', $reservationKey)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            // Fail closed if another transaction attached the reservation
            // after our initial lookup: re-run expiration with proper locks.
            $actualOrderId = $reservation->order_id === null
                ? null
                : (int) $reservation->order_id;

            if ($actualOrderId !== ($orderId === null ? null : (int) $orderId)) {
                return false;
            }

            // Only pending-payment orders may have their stock released.
            // Paid and review orders remain protected.
            if ($order !== null && $order->payment_status !== 'pending') {
                return false;
            }

            if ($reservation->expires_at === null) {
                return false;
            }

            if ($reservation->expires_at->isFuture()) {
                return false;
            }

            $quantity = $reservation->active_qty;

            if ($quantity <= 0) {
                return false;
            }

            $stock = InventoryStock::query()
                ->whereKey($reservation->inventory_stock_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($stock->reserved_qty < $quantity) {
                throw new DomainException(
                    'Виявлено невідповідність складського резерву.'
                );
            }

            $stock->reserved_qty -= $quantity;
            $stock->save();

            $reservation->released_qty += $quantity;
            $reservation->save();

            DB::table('inventory_reservation_operations')->insert([
                'operation_key' => (string) Str::uuid(),
                'inventory_reservation_id' => $reservation->id,
                'type' => 'release',
                'quantity' => $quantity,
                'created_at' => now(),
            ]);

            return true;
        }, 3);
    }


    /**
     * Атомарне переміщення товару між складами.
     *
     * Повертає ID переміщення.
     */
    public function transfer(
        int $fromWarehouseId,
        int $toWarehouseId,
        int $variantId,
        int $quantity,
        string $reference,
        string $transferKey,
        ?string $note = null
    ): int {
        $this->validateQuantity($quantity);
        $this->validateReference($reference);

        if (
            $fromWarehouseId <= 0 ||
            $toWarehouseId <= 0 ||
            $variantId <= 0 ||
            $fromWarehouseId === $toWarehouseId
        ) {
            throw new InvalidArgumentException(
                'Некоректні склади або товар.'
            );
        }

        if (!Str::isUuid($transferKey)) {
            throw new InvalidArgumentException(
                'Некоректний UUID переміщення.'
            );
        }

        $transferKey = strtolower($transferKey);
        $reference = trim($reference);

        return DB::transaction(function () use (
            $fromWarehouseId,
            $toWarehouseId,
            $variantId,
            $quantity,
            $reference,
            $transferKey,
            $note
        ) {
            // Серіалізуємо запити з однаковим UUID.
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['inventory-transfer:' . $transferKey]
            );

            $existing = DB::table('inventory_transfers')
                ->where('transfer_key', $transferKey)
                ->first();

            if ($existing) {
                if (
                    (int) $existing->from_warehouse_id !== $fromWarehouseId ||
                    (int) $existing->to_warehouse_id !== $toWarehouseId ||
                    (int) $existing->variant_id !== $variantId ||
                    (int) $existing->quantity !== $quantity ||
                    $existing->reference !== $reference ||
                    $existing->note !== $note
                ) {
                    throw new InvalidArgumentException(
                        'UUID переміщення вже використано '
                        . 'з іншими параметрами.'
                    );
                }

                return (int) $existing->id;
            }

            // На складі-відправнику залишок має існувати.
            $sourceId = DB::table('inventory_stocks')
                ->where('warehouse_id', $fromWarehouseId)
                ->where('variant_id', $variantId)
                ->value('id');

            if ($sourceId === null) {
                throw new DomainException(
                    'На складі-відправнику товар відсутній.'
                );
            }

            // Створюємо нульовий залишок складу-отримувача,
            // якщо товар туди ще не надходив.
            DB::table('inventory_stocks')->insertOrIgnore([
                'warehouse_id' => $toWarehouseId,
                'variant_id' => $variantId,
                'on_hand_qty' => 0,
                'reserved_qty' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $targetId = DB::table('inventory_stocks')
                ->where('warehouse_id', $toWarehouseId)
                ->where('variant_id', $variantId)
                ->value('id');

            if ($targetId === null) {
                throw new DomainException(
                    'Не вдалося отримати складський залишок.'
                );
            }

            /*
             * Блокуємо обидва залишки в однаковому
             * порядку за ID.
             *
             * Це зменшує ризик deadlock при зустрічних
             * переміщеннях між складами.
             */
            $ids = [(int) $sourceId, (int) $targetId];
            sort($ids, SORT_NUMERIC);

            $locked = [];

            foreach ($ids as $id) {
                $locked[$id] = InventoryStock::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $source = $locked[(int) $sourceId];
            $target = $locked[(int) $targetId];

            // Зарезервований товар переміщувати не можна.
            if ($source->available_qty < $quantity) {
                throw new DomainException(
                    'Недостатньо доступного товару '
                    . 'для переміщення.'
                );
            }

            // Списання та зарахування.
            $source->on_hand_qty -= $quantity;
            $target->on_hand_qty += $quantity;

            $source->save();
            $target->save();

            $transferId = DB::table('inventory_transfers')
                ->insertGetId([
                    'transfer_key' => $transferKey,
                    'from_warehouse_id' => $fromWarehouseId,
                    'to_warehouse_id' => $toWarehouseId,
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'reference' => $reference,
                    'note' => $note,
                    'created_at' => now(),
                ]);

            // Дві пов'язані операції у журналі руху.
            $movementReference = 'TRF-' . $transferKey;

            $this->recordMovement(
                $source,
                'transfer_out',
                -$quantity,
                $movementReference,
                $note
            );

            $this->recordMovement(
                $target,
                'transfer_in',
                $quantity,
                $movementReference,
                $note
            );

            return (int) $transferId;
        }, 3);
    }


    /**
     * Сверяет системный остаток с фактическим.
     *
     * countedQty — новое общее количество,
     * включая товар, зарезервированный клиентами.
     *
     * Возвращает ID записи корректировки.
     */
    public function reconcileStockCount(
        int $warehouseId,
        int $variantId,
        int $countedQty,
        string $reference,
        string $adjustmentKey,
        int $expectedRevision,
        string $reason = 'stocktake',
        ?string $note = null
    ): int {
        if (
            $warehouseId <= 0 ||
            $variantId <= 0 ||
            $countedQty < 0
        ) {
            throw new InvalidArgumentException(
                'Некоректні параметри інвентаризації.'
            );
        }


        if ($expectedRevision < 0) {
            throw new InvalidArgumentException(
                'Версія складського залишку не може бути від’ємною.'
            );
        }

        $this->validateReference($reference);

        if (!Str::isUuid($adjustmentKey)) {
            throw new InvalidArgumentException(
                'Некоректний UUID коригування.'
            );
        }

        $reason = strtolower(trim($reason));

        if (!in_array(
            $reason,
            ['stocktake', 'damage', 'loss', 'correction'],
            true
        )) {
            throw new InvalidArgumentException(
                'Невідома причина коригування.'
            );
        }

        $reference = trim($reference);
        $adjustmentKey = strtolower($adjustmentKey);

        return DB::transaction(function () use (
            $warehouseId,
            $variantId,
            $countedQty,
            $reference,
            $adjustmentKey,
            $reason,
            $note,
            $expectedRevision
        ) {
            /*
             * Блокировка для одинакового UUID.
             */
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['inventory-adjustment:' . $adjustmentKey]
            );

            /*
             * Если запрос уже выполнялся, проверяем
             * параметры и возвращаем старый результат.
             */
            $existing = DB::table('inventory_adjustments as a')
                ->join(
                    'inventory_stocks as s',
                    's.id',
                    '=',
                    'a.inventory_stock_id'
                )
                ->where('a.adjustment_key', $adjustmentKey)
                ->select(
                    'a.id',
                    'a.counted_qty',
                    'a.reference',
                    'a.reason',
                    'a.note',
                    's.warehouse_id',
                    's.variant_id',
                    'a.expected_revision',
                )
                ->first();


            if ($existing !== null) {
                $storedRevision = $existing->expected_revision === null
                    ? null
                    : (int) $existing->expected_revision;

                if (
                    (int) $existing->warehouse_id !== $warehouseId
                    || (int) $existing->variant_id !== $variantId
                    || (int) $existing->counted_qty !== $countedQty
                    || $existing->reference !== $reference
                    || $existing->reason !== $reason
                    || $existing->note !== $note
                    || $storedRevision !== $expectedRevision
                ) {
                    throw new InvalidArgumentException(
                        'UUID коригування вже використано '
                        . 'з іншими параметрами.'
                    );
                }

                return (int) $existing->id;
            }


            /*
             * Создаём нулевой остаток, если записи
             * ещё не было, и блокируем его.
             */
            $stock = $this->lockStock(
                $warehouseId,
                $variantId,
                true
            );

            if ($stock === null) {
                throw new DomainException(
                    'Не вдалося отримати складський залишок.'
                );
            }

            /*
             * Нельзя установить остаток меньше
             * уже зарезервированного количества.
             */
            if ($countedQty < $stock->reserved_qty) {
                throw new DomainException(
                    'Фактичний залишок менший '
                    . 'за зарезервовану кількість.'
                );
            }


            if ((int) $stock->revision !== $expectedRevision) {
                throw new DomainException(
                    'Дані інвентаризації застаріли: '
                    . 'залишок змінився. Повторіть підрахунок.'
                );
            }


            $previousQty = (int) $stock->on_hand_qty;
            $delta = $countedQty - $previousQty;

            /*
             * Изменяем только физический остаток.
             * Резервы клиентов не трогаем.
             */
            $stock->on_hand_qty = $countedQty;
            $stock->save();

            /*
             * Записываем историю корректировки.
             * Она сохраняется даже при delta = 0.
             */
            $adjustmentId = DB::table('inventory_adjustments')
                ->insertGetId([
                    'adjustment_key' => $adjustmentKey,
                    'inventory_stock_id' => $stock->id,
                    'previous_qty' => $previousQty,
                    'counted_qty' => $countedQty,
                    'quantity_delta' => $delta,
                    'reason' => $reason,
                    'reference' => $reference,
                    'note' => $note,
                    'created_at' => now(),
                    'expected_revision' => $expectedRevision,
                ]);

            /*
             * Журнал движения товара принимает
             * только ненулевые изменения.
             */
            if ($delta !== 0) {
                $movementNote = 'Reason: ' . $reason;

                if ($note !== null) {
                    $movementNote .= '; ' . $note;
                }

                $this->recordMovement(
                    $stock,
                    'adjustment',
                    $delta,
                    'ADJ-' . $adjustmentKey,
                    $movementNote
                );
            }

            return (int) $adjustmentId;
        }, 3);
    }


    /**
     * Идемпотентная приёмка товара.
     *
     * Один receiptKey может увеличить остаток
     * только один раз.
     */
    public function receiveOnce(
        int $warehouseId,
        int $variantId,
        int $quantity,
        string $reference,
        string $receiptKey,
        ?string $note = null
    ): InventoryStock {
        $this->validateQuantity($quantity);
        $this->validateReference($reference);

        if ($warehouseId <= 0 || $variantId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний склад або товар.'
            );
        }

        if (!Str::isUuid($receiptKey)) {
            throw new InvalidArgumentException(
                'Некоректний UUID приймання.'
            );
        }

        $receiptKey = strtolower($receiptKey);
        $reference = trim($reference);

        return DB::transaction(function () use (
            $warehouseId,
            $variantId,
            $quantity,
            $reference,
            $receiptKey,
            $note
        ) {
            /*
             * Сериализуем операции с одинаковым UUID.
             */
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['inventory-receipt:' . $receiptKey]
            );

            /*
             * Проверяем, проводилась ли приёмка ранее.
             */
            $existing = DB::table('inventory_receipts')
                ->where('receipt_key', $receiptKey)
                ->first();

            if ($existing !== null) {
                $stock = InventoryStock::query()
                    ->findOrFail($existing->inventory_stock_id);

                if (
                    (int) $stock->warehouse_id !== $warehouseId
                    || (int) $stock->variant_id !== $variantId
                    || (int) $existing->quantity !== $quantity
                    || $existing->reference !== $reference
                    || $existing->note !== $note
                ) {
                    throw new InvalidArgumentException(
                        'UUID приймання вже використано '
                        . 'з іншими параметрами.'
                    );
                }

                // Повторный запрос: ничего не зачисляем.
                return $stock;
            }

            /*
             * Создаём складской остаток при необходимости
             * и блокируем его до конца транзакции.
             */
            $stock = $this->lockStock(
                $warehouseId,
                $variantId,
                true
            );

            if ($stock === null) {
                throw new DomainException(
                    'Не вдалося отримати складський залишок.'
                );
            }

            /*
             * Зачисляем товар.
             */
            $stock->on_hand_qty += $quantity;
            $stock->save();

            /*
             * Сохраняем уникальную операцию приёмки.
             */
            DB::table('inventory_receipts')->insert([
                'receipt_key' => $receiptKey,
                'inventory_stock_id' => $stock->id,
                'quantity' => $quantity,
                'reference' => $reference,
                'note' => $note,
                'created_at' => now(),
            ]);

            /*
             * Регистрируем физическое движение.
             * По UUID его можно связать с приёмкой.
             */
            $this->recordMovement(
                $stock,
                'receipt',
                $quantity,
                'RCV-' . $receiptKey,
                $note
            );

            return $stock->refresh();
        }, 3);
    }


    private function validateQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Кількість повинна бути більшою за нуль.'
            );
        }
    }

    private function validateReference(string $reference): void
    {
        if (
            trim($reference) === '' ||
            strlen($reference) > 150
        ) {
            throw new InvalidArgumentException(
                'Некоректний номер документа.'
            );
        }
    }
}
