<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CheckoutCodRecipientService
{
    public function __construct(
        private readonly CheckoutCodDeliverySelectionService $selections,
        private readonly OrderFinancialSnapshotService $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function save(
        string $orderKey,
        string $recipientKey,
        string $cityRef,
        string $firstName,
        string $lastName,
        string $phone,
        ?string $middleName = null,
    ): array {
        if (
            !Str::isUuid($orderKey)
            || !Str::isUuid($recipientKey)
            || !Str::isUuid($cityRef)
        ) {
            throw new InvalidArgumentException('Invalid COD recipient UUID');
        }

        $orderKey = strtolower($orderKey);
        $recipientKey = strtolower($recipientKey);
        $cityRef = strtolower($cityRef);
        $firstName = $this->normalizeName($firstName);
        $lastName = $this->normalizeName($lastName);

        $middleName = $middleName === null || trim($middleName) === ''
            ? null
            : $this->normalizeName($middleName);

        $phone = trim($phone);

        if (!preg_match('/^\+380[0-9]{9}$/D', $phone)) {
            throw new InvalidArgumentException('Invalid recipient phone');
        }

        $fingerprint = $this->fingerprint([
            $orderKey,
            $cityRef,
            $firstName,
            $lastName,
            $middleName,
            $phone,
        ]);

        return DB::transaction(function () use (
            $orderKey,
            $recipientKey,
            $cityRef,
            $firstName,
            $lastName,
            $middleName,
            $phone,
            $fingerprint,
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null || $order->payment_status !== 'pending') {
                throw new DomainException('COD order is not pending');
            }

            $orderId = (int) $order->id;
            $selection = $this->assertActiveSelection($orderId);

            $existing = DB::table('checkout_cod_recipients')
                ->where('order_id', $orderId)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->recipient_key !== $recipientKey
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $fingerprint
                    )
                    || (int) $existing->cod_delivery_selection_id
                    !== (int) $selection['id']
                ) {
                    throw new DomainException('COD recipient is immutable');
                }

                return $this->present($existing, $orderKey);
            }

            if (
                DB::table('checkout_cod_recipients')
                    ->where('recipient_key', $recipientKey)
                    ->exists()
            ) {
                throw new DomainException('COD recipient key already used');
            }

            $id = DB::table('checkout_cod_recipients')->insertGetId([
                'recipient_key' => $recipientKey,
                'order_id' => $orderId,
                'cod_delivery_selection_id' => (int) $selection['id'],
                'city_ref' => $cityRef,
                'first_name_ciphertext' => Crypt::encryptString($firstName),
                'last_name_ciphertext' => Crypt::encryptString($lastName),
                'middle_name_ciphertext' => $middleName === null
                    ? null
                    : Crypt::encryptString($middleName),
                'phone_ciphertext' => Crypt::encryptString($phone),
                'request_hmac' => $fingerprint,
                'checkout_expires_at' => $selection['checkout_expires_at'],
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_recipients')
                    ->where('id', $id)
                    ->first(),
                $orderKey,
            );
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_recipients as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.order_id', $orderId)
            ->first(['r.*', 'o.order_key']);

        return $row === null
            ? null
            : $this->present($row, (string) $row->order_key);
    }

    /** @return array<string, mixed> */
    private function assertActiveSelection(int $orderId): array
    {
        $selection = $this->selections->findForOrder($orderId);

        if (
            $selection === null
            || ($selection['status'] ?? null) !==
            'awaiting_carrier_verification'
            || ($selection['carrier_code'] ?? null) !== 'nova_poshta'
            || !in_array(
                $selection['delivery_type'] ?? null,
                ['branch', 'parcel_locker'],
                true
            )
            || !Str::isUuid(
                (string) ($selection['destination_ref'] ?? '')
            )
            || CarbonImmutable::parse(
                (string) $selection['checkout_expires_at']
            )->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
        ) {
            throw new DomainException(
                'COD delivery selection is unavailable'
            );
        }

        $snapshot = $this->snapshots->findForOrder($orderId);

        if (
            $snapshot === null
            || $snapshot['goods_payable_minor'] !==
            (int) $selection['goods_due_minor']
            || $snapshot['currency_code'] !== $selection['currency_code']
            || !hash_equals(
                $snapshot['sha256'],
                (string) $selection['snapshot_sha256']
            )
        ) {
            throw new DomainException('COD financial snapshot mismatch');
        }

        $preparation = DB::table('checkout_order_preparations')
            ->where('order_id', $orderId)
            ->first(['id']);

        if ($preparation === null) {
            throw new DomainException('COD preparation missing');
        }

        $reservations = DB::table('checkout_order_stock_reservations as c')
            ->join(
                'inventory_reservations as r',
                'r.id',
                '=',
                'c.inventory_reservation_id'
            )
            ->where('c.checkout_preparation_id', $preparation->id)
            ->get([
                'c.quantity as line_quantity',
                'r.quantity as reserved_quantity',
                'r.shipped_qty',
                'r.released_qty',
                'r.expires_at',
            ]);

        if ($reservations->isEmpty()) {
            throw new DomainException('COD stock reservation missing');
        }

        foreach ($reservations as $reservation) {
            if (
                (int) $reservation->line_quantity !==
                (int) $reservation->reserved_quantity
                || (int) $reservation->shipped_qty !== 0
                || (int) $reservation->released_qty !== 0
                || $reservation->expires_at === null
                || CarbonImmutable::parse(
                    (string) $reservation->expires_at
                )->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException(
                    'COD stock reservation is no longer active'
                );
            }
        }

        return $selection;
    }

    private function normalizeName(string $name): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new InvalidArgumentException('Invalid recipient name');
        }

        $name = trim($name);

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 80
            || !preg_match(
                '/^\p{L}[\p{L}\p{M} .\x{2019}\'-]*$/uD',
                $name
            )
        ) {
            throw new InvalidArgumentException('Invalid recipient name');
        }

        return $name;
    }

    /** @param array<int, mixed> $parts */
    private function fingerprint(array $parts): string
    {
        $key = config('app.key');

        if (!is_string($key) || $key === '') {
            throw new DomainException(
                'Application encryption key is missing'
            );
        }

        return hash_hmac(
            'sha256',
            json_encode($parts, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string, mixed> */
    private function present(object $row, string $orderKey): array
    {
        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'order_key' => $orderKey,
            'recipient_key' => (string) $row->recipient_key,
            'cod_delivery_selection_id' =>
                (int) $row->cod_delivery_selection_id,
            'city_ref' => (string) $row->city_ref,
            'first_name' => Crypt::decryptString(
                (string) $row->first_name_ciphertext
            ),
            'last_name' => Crypt::decryptString(
                (string) $row->last_name_ciphertext
            ),
            'middle_name' => $row->middle_name_ciphertext === null
                ? null
                : Crypt::decryptString(
                    (string) $row->middle_name_ciphertext
                ),
            'phone' => Crypt::decryptString(
                (string) $row->phone_ciphertext
            ),
            'checkout_expires_at' => (string) $row->checkout_expires_at,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
