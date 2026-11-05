<?php

namespace App\Services\Pricing;

use App\Services\Inventory\OrderInventoryService;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Promotions\PromotionReservationService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Тільки внутрішня підготовка pending-order.
 * Довірений викликаючий код відповідає за ідентичність покупця та ринок.
 * Ніяких HTTP endpoint або платіжних операцій у цьому сервісі немає.
 */
final class CheckoutOrderPreparationService
{
    public function __construct(
        private readonly CheckoutPricingPreviewService $pricing,
        private readonly OrderInventoryService $orderInventory,
        private readonly LoyaltyTransactionService $loyaltyTransactions,
        private readonly PromotionReservationService $promotionReservations,
        private readonly OrderFinancialSnapshotService $snapshots,
    ) {
    }

    /**
     * @param array<int,array{variant_id:int,quantity:int}> $items
     * @return array<string,mixed>
     */
    public function prepare(
        string $orderKey,
        array $items,
        string $marketCode,
        string $priceListCode,
        DateTimeInterface $expiresAt,
        ?int $verifiedUserId = null,
        ?int $requestedBonusUnits = null,
        ?string $promotionCode = null,
    ): array {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid order UUID');
        }

        $orderKey = strtolower($orderKey);
        $marketCode = trim($marketCode);
        $priceListCode = trim($priceListCode);
        $promotionCode = $promotionCode === null
            ? null : strtoupper(trim($promotionCode));

        if (
            count($items) < 1 || count($items) > 200
            || $marketCode === '' || strlen($marketCode) > 16
            || $priceListCode === '' || strlen($priceListCode) > 80
            || ($verifiedUserId !== null && $verifiedUserId <= 0)
            || ($requestedBonusUnits !== null && $requestedBonusUnits < 0)
            || ($requestedBonusUnits > 0 && $verifiedUserId === null)
            || (
                $promotionCode !== null
                && !preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D', $promotionCode)
            )
        ) {
            throw new InvalidArgumentException('Invalid checkout parameters');
        }

        $quantities = [];

        foreach ($items as $item) {
            if (
                !is_array($item)
                || !isset($item['variant_id'], $item['quantity'])
                || !is_int($item['variant_id']) || $item['variant_id'] <= 0
                || !is_int($item['quantity']) || $item['quantity'] < 1
                || $item['quantity'] > 99
                || isset($quantities[$item['variant_id']])
            ) {
                throw new InvalidArgumentException('Invalid or duplicated cart variant');
            }

            $quantities[$item['variant_id']] = $item['quantity'];
        }

        ksort($quantities, SORT_NUMERIC);

        $normalizedItems = [];
        foreach ($quantities as $id => $quantity) {
            $normalizedItems[] = [
                'variant_id' => (int) $id,
                'quantity' => $quantity,
            ];
        }

        $expires = CarbonImmutable::instance($expiresAt)->utc();
        if ($expires->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new InvalidArgumentException('Checkout expiration must be in future');
        }

        // null => 0 лише під час розрахунку: бонуси без вибору не списуємо.
        $bonusRequest = $requestedBonusUnits ?? 0;

        // Для замовлень БЕЗ купона зберігаємо попередній формат
        // fingerprint v1, щоб не ламати їхню ідемпотентність.
        $fingerprintParts = [
            $promotionCode === null ? 'v1' : 'v2',
            $orderKey, $normalizedItems, $marketCode, $priceListCode,
            $verifiedUserId, $requestedBonusUnits,
        ];
        if ($promotionCode !== null) {
            $fingerprintParts[] = $promotionCode;
        }
        $fingerprintParts[] = $expires->format('Y-m-d H:i:s.uP');

        $fingerprint = hash('sha256', json_encode(
            $fingerprintParts, JSON_THROW_ON_ERROR
        ));

        return DB::transaction(function () use (
            $orderKey, $normalizedItems, $quantities,
            $marketCode, $priceListCode, $verifiedUserId,
            $bonusRequest, $promotionCode, $expires, $fingerprint
        ): array {
            $orderId = $this->orderInventory->createPendingOrder($orderKey);

            $order = DB::table('orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            $existing = DB::table('checkout_order_preparations')
                ->where('order_id', $orderId)->first();

            if ($existing !== null) {
                if (!hash_equals((string) $existing->request_sha256, $fingerprint)) {
                    throw new DomainException(
                        'Order key reused with different checkout parameters'
                    );
                }

                return $this->readPrepared($orderId);
            }

            if (
                $order === null || $order->payment_status !== 'pending'
                || DB::table('inventory_reservations')
                    ->where('order_id', $orderId)->exists()
                || DB::table('order_financial_snapshots')
                    ->where('order_id', $orderId)->exists()
                || DB::table('promotion_reservations')
                    ->where('order_id', $orderId)->exists()
            ) {
                throw new DomainException('Order is not empty and pending');
            }

            $market = DB::table('markets')
                ->where('code', $marketCode)->where('is_active', true)
                ->first(['id', 'country_code', 'currency_code']);

            $list = DB::table('price_lists')
                ->where('code', $priceListCode)
                ->where('type', 'retail')->where('is_active', true)
                ->first(['id', 'country_code', 'currency_code']);

            if (
                $market === null || $list === null
                || $market->country_code !== $list->country_code
                || $market->currency_code !== $list->currency_code
            ) {
                throw new DomainException('Incompatible market or price list');
            }

            // Утримуємо блокування цін до COMMIT.
            $locked = DB::table('variant_prices')
                ->where('price_list_id', $list->id)
                ->whereIn('variant_id', array_keys($quantities))
                ->orderBy('variant_id')->lockForUpdate()
                ->get(['variant_id']);

            if ($locked->count() !== count($quantities)) {
                throw new DomainException('Cart has missing prices');
            }

            $quote = $this->pricing->preview(
                $normalizedItems,
                $marketCode,
                $priceListCode,
                $promotionCode,
                $verifiedUserId,
                $bonusRequest
            );

            // Не продовжуємо замовлення, якщо запитаний купон недійсний.
            if ($promotionCode !== null) {
                $promo = $quote['promotion'] ?? null;
                if (
                    !is_array($promo)
                    || ($promo['valid'] ?? null) !== true
                    || ($promo['code'] ?? null) !== $promotionCode
                    || !is_int($quote['promotion_discount_minor'] ?? null)
                    || $quote['promotion_discount_minor'] <= 0
                ) {
                    throw new DomainException('Requested promotion is unavailable');
                }
            }

            if (
                $bonusRequest > 0
                && (
                    ($quote['loyalty']['applied_units'] ?? null) !== $bonusRequest
                    || ($quote['loyalty_discount_minor'] ?? 0) <= 0
                )
            ) {
                throw new DomainException('Requested bonus redemption is not available');
            }

            // Промокод резервуємо ДО складу й бонусів.
            // Будь-яка наступна помилка відкотить і цю квоту.
            $promotionReservation = null;
            if ($promotionCode !== null) {
                $promotionReservation = $this->promotionReservations->reserve(
                    $orderId,
                    (string) Str::uuid(),
                    $promotionCode,
                    $normalizedItems,
                    $priceListCode,
                    $expires,
                    $verifiedUserId
                );

                if (
                    $promotionReservation['status'] !== 'reserved'
                    || $promotionReservation['promotion_code'] !== $promotionCode
                    || $promotionReservation['currency_code'] !== $list->currency_code
                    || $promotionReservation['discount_minor']
                    !== $quote['promotion_discount_minor']
                ) {
                    throw new DomainException(
                        'Promotion reservation and financial quote differ'
                    );
                }
            }

            // Підтримуємо лише власні активні склади потрібної країни.
            $warehouseIds = DB::table('warehouses')
                ->where('country_code', $market->country_code)
                ->where('kind', 'owned')->where('is_active', true)
                ->pluck('id')->all();

            if ($warehouseIds === []) {
                throw new DomainException('No active owned warehouses in market');
            }

            $stockReservations = [];

            foreach ($normalizedItems as $line) {
                $variantId = $line['variant_id'];
                $quantity = $line['quantity'];

                $stocks = DB::table('inventory_stocks')
                    ->where('variant_id', $variantId)
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->orderBy('warehouse_id')->lockForUpdate()
                    ->get(['warehouse_id', 'on_hand_qty', 'reserved_qty']);

                $warehouseId = null;
                foreach ($stocks as $stock) {
                    if ((int) $stock->on_hand_qty - (int) $stock->reserved_qty >= $quantity) {
                        $warehouseId = (int) $stock->warehouse_id;
                        break;
                    }
                }

                if ($warehouseId === null) {
                    throw new DomainException(
                        'Not enough stock in a single allowed warehouse'
                    );
                }

                $reservation = $this->orderInventory->reserveLine(
                    $orderKey,
                    $warehouseId,
                    $variantId,
                    $quantity,
                    'CHECKOUT:' . $orderKey . ':SKU:' . $variantId,
                    (string) Str::uuid(),
                    $expires
                );

                $stockReservations[] = [
                    'variant_id' => $variantId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'inventory_reservation_id' => (int) $reservation->id,
                ];
            }

            $walletId = null;
            $bonusReservationId = null;
            $bonusUnits = (int) ($quote['loyalty']['applied_units'] ?? 0);

            if ($bonusUnits > 0) {
                $walletId = DB::table('loyalty_accounts as a')
                    ->join('loyalty_wallets as w', 'w.loyalty_account_id', '=', 'a.id')
                    ->where('a.user_id', $verifiedUserId)
                    ->where('a.identity_state', 'verified')
                    ->where('w.market_id', $market->id)
                    ->where('w.status', 'active')
                    ->value('w.id');

                if ($walletId === null) {
                    throw new DomainException('Verified loyalty wallet not found');
                }

                $walletId = (int) $walletId;
                $bonusReservation = $this->loyaltyTransactions->reserve(
                    $walletId,
                    'checkout-reserve:' . $orderKey,
                    'checkout-bonus:' . $orderKey,
                    'checkout-order:' . $orderKey,
                    $bonusUnits,
                    $expires
                );

                $bonusReservationId = (int) $bonusReservation['reservation_id'];
            }

            // Фінансовий snapshot містить реальну промознижку
            // і бонуси після промо. Зберігаємо в тій самій транзакції.
            $saved = $this->snapshots->store($orderId, $quote);

            $preparationId = DB::table('checkout_order_preparations')->insertGetId([
                'order_id' => $orderId,
                'financial_snapshot_id' => $saved['id'],
                'market_id' => $market->id,
                'price_list_id' => $list->id,
                'user_id' => $verifiedUserId,
                'loyalty_wallet_id' => $walletId,
                'loyalty_reservation_id' => $bonusReservationId,
                'bonus_units_reserved' => $bonusUnits,
                'request_sha256' => $fingerprint,
                'expires_at' => $expires,
            ]);

            foreach ($stockReservations as $stock) {
                DB::table('checkout_order_stock_reservations')->insert([
                    'checkout_preparation_id' => $preparationId,
                    ...$stock,
                ]);
            }

            return $this->readPrepared($orderId);
        }, 3);
    }

    /** @return array<string,mixed> */
    private function readPrepared(int $orderId): array
    {
        $row = DB::table('checkout_order_preparations as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.order_id', $orderId)
            ->first([
                'p.id', 'p.order_id', 'p.request_sha256', 'p.expires_at',
                'p.user_id', 'p.loyalty_wallet_id', 'p.loyalty_reservation_id',
                'p.bonus_units_reserved', 'o.order_key', 'o.payment_status',
            ]);

        if ($row === null) {
            throw new DomainException('Order preparation not found');
        }

        $stocks = DB::table('checkout_order_stock_reservations as s')
            ->join('inventory_reservations as r', 'r.id', '=', 's.inventory_reservation_id')
            ->where('s.checkout_preparation_id', $row->id)
            ->orderBy('s.variant_id')
            ->get(['s.variant_id', 's.warehouse_id', 's.quantity', 'r.reservation_key'])
            ->map(static fn (object $s): array => [
                'variant_id' => (int) $s->variant_id,
                'warehouse_id' => (int) $s->warehouse_id,
                'quantity' => (int) $s->quantity,
                'reservation_key' => (string) $s->reservation_key,
            ])->all();

        $snapshot = $this->snapshots->findForOrder($orderId);
        if ($snapshot === null) {
            throw new DomainException('Financial snapshot missing');
        }

        $promotionId = DB::table('promotion_reservations')
            ->where('order_id', $orderId)->value('id');

        $promotion = $promotionId === null
            ? null : $this->promotionReservations->find((int) $promotionId);

        $snapshotCode = $snapshot['snapshot']['promotion_code'] ?? null;
        $snapshotPromoMinor = $snapshot['snapshot']['promotion_discount_minor'] ?? null;

        if (
            ($promotion === null && $snapshotCode !== null)
            || ($promotion !== null && (
                    $promotion['promotion_code'] !== $snapshotCode
                    || $promotion['discount_minor'] !== $snapshotPromoMinor
                    || $promotion['currency_code'] !== $snapshot['currency_code']
                    || $promotion['user_id'] !== (
                    $row->user_id === null ? null : (int) $row->user_id
                    )
                ))
        ) {
            throw new DomainException('Stored promotion does not match financial snapshot');
        }

        return [
            'order_id' => (int) $row->order_id,
            'order_key' => (string) $row->order_key,
            'payment_status' => (string) $row->payment_status,
            'request_sha256' => (string) $row->request_sha256,
            'expires_at' => (string) $row->expires_at,
            'stock_reservations' => $stocks,
            'promotion_reservation' => $promotion,
            'loyalty_wallet_id' => $row->loyalty_wallet_id === null
                ? null : (int) $row->loyalty_wallet_id,
            'loyalty_reservation_id' => $row->loyalty_reservation_id === null
                ? null : (int) $row->loyalty_reservation_id,
            'bonus_units_reserved' => (int) $row->bonus_units_reserved,
            'financial_snapshot' => $snapshot,
        ];
    }
}
