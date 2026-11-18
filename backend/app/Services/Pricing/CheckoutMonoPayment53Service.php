<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * One invoice per prepared order.
 * A missing provider response is NEVER retried blindly.
 */
final class CheckoutMonoPayment53Service
{
    public const CREATE_ABILITY = 'payments.mono.create';
    public const RECONCILE_ABILITY = 'payments.mono.reconcile';

    public function __construct(
        private readonly CheckoutMonoGateway53 $gateway,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly CheckoutPaymentAttemptService $attempts,
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly CheckoutPaymentFinalizationService $finalization,
    ) {
    }

    public function start(
        string $orderKey,
        string $operationKey
    ): array {
        $this->authorize(self::CREATE_ABILITY);

        $orderKey = $this->uuid($orderKey);
        $operationKey = $this->uuid($operationKey);

        $this->enabled();

        $work = DB::transaction(function () use (
            $orderKey,
            $operationKey
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('Checkout order not found');
            }

            $existing = DB::table('checkout_mono_invoice_launches')
                ->where('order_id', $order->id)
                ->first();

            if ($existing !== null) {
                if (
                    (string) $existing->operation_key !== $operationKey
                ) {
                    throw new DomainException(
                        'One Mono payment launch per order'
                    );
                }

                if ($existing->status === 'creating') {
                    throw new DomainException(
                        'Mono request outcome uncertain; investigate before retrying'
                    );
                }

                return [
                    'new' => false,
                    'id' => (int) $existing->id,
                ];
            }

            if ($order->payment_status !== 'pending') {
                throw new DomainException(
                    'Online invoice requires pending order'
                );
            }

            $choice = DB::table('checkout_payment_choices')
                ->where('order_id', $order->id)
                ->first();

            if (
                $choice === null
                || $choice->provider_code !== 'mono'
                || !in_array(
                    $choice->payment_method,
                    ['card', 'apple_pay', 'google_pay'],
                    true
                )
            ) {
                throw new DomainException(
                    'Order has no Mono payment choice'
                );
            }

            if (
                DB::table('checkout_payment_attempts')
                    ->where('order_id', $order->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Order already has bound payment attempt'
                );
            }

            $prep = DB::table('checkout_order_preparations')
                ->where('order_id', $order->id)
                ->first();

            $snapshot = $this->snapshots->findForOrder(
                (int) $order->id
            );

            if (
                $prep === null
                || $snapshot === null
                || (int) $prep->financial_snapshot_id !== $snapshot['id']
                || $snapshot['currency_code'] !== 'UAH'
                || $snapshot['goods_payable_minor'] <= 0
            ) {
                throw new DomainException(
                    'Unsupported Mono checkout financial context'
                );
            }

            $expires = CarbonImmutable::parse(
                $prep->expires_at
            )->utc();

            $validity = (int) floor(
                    $expires->getTimestamp()
                    - CarbonImmutable::now('UTC')->getTimestamp()
                ) - 5;

            if ($validity < 60) {
                throw new DomainException(
                    'Insufficient remaining checkout reservation time'
                );
            }

            $expiryText = $expires->format('Y-m-d H:i:s.uP');

            $hash = $this->requestHash(
                $orderKey,
                $operationKey,
                $snapshot['goods_payable_minor'],
                $expiryText
            );

            $id = (int) DB::table(
                'checkout_mono_invoice_launches'
            )->insertGetId([
                'order_id' => $order->id,
                'operation_key' => $operationKey,
                'amount_minor' => $snapshot['goods_payable_minor'],
                'currency_code' => 'UAH',
                'expires_at' => $expiryText,
                'request_sha256' => $hash,
                'status' => 'creating',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'new' => true,
                'id' => $id,
                'amount' => $snapshot['goods_payable_minor'],
                'validity' => min(3600, $validity),
            ];
        }, 3);

        if ($work['new']) {
            // External call OUTSIDE DB transaction.
            // Never retry this POST automatically.
            $invoice = $this->gateway->create(
                $work['amount'],
                $orderKey,
                $work['validity']
            );

            DB::transaction(function () use (
                $work,
                $invoice
            ): void {
                $row = DB::table('checkout_mono_invoice_launches')
                    ->where('id', $work['id'])
                    ->lockForUpdate()
                    ->first();

                if ($row === null || $row->status !== 'creating') {
                    throw new DomainException(
                        'Mono invoice launch changed unexpectedly'
                    );
                }

                DB::table('checkout_mono_invoice_launches')
                    ->where('id', $row->id)
                    ->update([
                        'status' => 'linked',
                        'provider_invoice_id' => $invoice['invoice_id'],
                        'payment_url' => $invoice['payment_url'],
                        'updated_at' => now(),
                    ]);
            }, 3);
        }

        return $this->finish((int) $work['id']);
    }

    private function finish(int $launchId): array
    {
        return DB::transaction(function () use (
            $launchId
        ): array {
            $candidate = DB::table('checkout_mono_invoice_launches')
                ->where('id', $launchId)
                ->first();

            if ($candidate === null) {
                throw new DomainException('Mono launch not found');
            }

            $order = DB::table('orders')
                ->where('id', $candidate->order_id)
                ->lockForUpdate()
                ->first(['id', 'order_key']);

            $row = DB::table('checkout_mono_invoice_launches')
                ->where('id', $launchId)
                ->lockForUpdate()
                ->first();

            if ($order === null || $row === null) {
                throw new DomainException(
                    'Mono launch lost its order'
                );
            }

            $this->assertLaunch(
                $row,
                (string) $order->order_key
            );

            if ($row->status === 'creating') {
                throw new DomainException(
                    'Mono invoice outcome remains uncertain'
                );
            }

            if ($row->status === 'linked') {
                $this->attempts->bindPreparedOrder(
                    (string) $order->order_key,
                    (string) $row->operation_key,
                    'mono',
                    (string) $row->provider_invoice_id,
                    CarbonImmutable::parse($row->expires_at)
                );

                DB::table('checkout_mono_invoice_launches')
                    ->where('id', $row->id)
                    ->update([
                        'status' => 'ready',
                        'updated_at' => now(),
                    ]);

                $row->status = 'ready';
            }

            $attempt = $this->attempts->findForOrder(
                (int) $row->order_id
            );

            if (
                $attempt === null
                || $attempt['provider_code'] !== 'mono'
                || $attempt['provider_payment_id']
                !== $row->provider_invoice_id
                || $attempt['amount_minor']
                !== (int) $row->amount_minor
            ) {
                throw new DomainException(
                    'Mono invoice does not reconcile with bound payment attempt'
                );
            }

            return $this->present(
                $row,
                (string) $order->order_key
            );
        }, 3);
    }

    public function webhook(
        string $rawBody,
        ?string $signatureBase64
    ): array {
        $this->enabled();

        if (!$this->gateway->verifyWebhook(
            $rawBody,
            $signatureBase64
        )) {
            throw new AuthorizationException(
                'Invalid Mono webhook signature'
            );
        }

        try {
            $payload = json_decode(
                $rawBody,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new InvalidArgumentException(
                'Invalid Mono webhook JSON'
            );
        }

        if (
            !is_array($payload)
            || !is_string($payload['invoiceId'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'Mono webhook invoice ID missing'
            );
        }

        return $this->verifiedProviderStatus(
            $payload['invoiceId']
        );
    }

    public function reconcile(string $invoiceId): array
    {
        $this->authorize(self::RECONCILE_ABILITY);
        $this->enabled();

        return $this->verifiedProviderStatus($invoiceId);
    }

    private function verifiedProviderStatus(
        string $invoiceId
    ): array {
        $row = DB::table('checkout_mono_invoice_launches')
            ->where('provider_invoice_id', $invoiceId)
            ->first();

        if ($row === null || $row->status !== 'ready') {
            throw new DomainException(
                'Mono invoice is not yet linked to checkout'
            );
        }

        $order = DB::table('orders')
            ->where('id', $row->order_id)
            ->first(['order_key']);

        if ($order === null) {
            throw new DomainException(
                'Mono invoice order not found'
            );
        }

        $this->assertLaunch(
            $row,
            (string) $order->order_key
        );

        // Trusted server-side verification.
        $status = $this->gateway->status($invoiceId);

        if (
            ($status['invoiceId'] ?? null) !== $invoiceId
            || ($status['reference'] ?? null)
            !== (string) $order->order_key
            || ($status['amount'] ?? null)
            !== (int) $row->amount_minor
            || ($status['ccy'] ?? null) !== 980
        ) {
            throw new DomainException(
                'Mono status does not match immutable checkout invoice'
            );
        }

        if (($status['status'] ?? null) !== 'success') {
            return [
                'invoice_id' => $invoiceId,
                'provider_status' =>
                    (string) ($status['status'] ?? 'unknown'),
                'capture_recorded' => false,
                'order_changed' => false,
            ];
        }

        if (
            isset($status['finalAmount'])
            && $status['finalAmount'] !== (int) $row->amount_minor
        ) {
            throw new DomainException(
                'Mono final amount differs from original checkout'
            );
        }

        if (!is_string($status['modifiedDate'] ?? null)) {
            throw new DomainException(
                'Mono success is missing confirmed timestamp'
            );
        }

        try {
            $capturedAt = CarbonImmutable::parse(
                $status['modifiedDate']
            )->utc();
        } catch (\Throwable) {
            throw new DomainException(
                'Mono capture timestamp invalid'
            );
        }

        $receipt = $this->receipts->recordVerifiedCapture(
            'mono',
            $invoiceId,
            $invoiceId,
            (string) $order->order_key,
            (int) $row->amount_minor,
            'UAH',
            $capturedAt,
            $invoiceId
        );

        // Existing OMS decides paid/review and safely captures
        // promotion and loyalty reservations.
        $decision = $this->finalization->finalize(
            $receipt['id']
        );

        return [
            'invoice_id' => $invoiceId,
            'provider_status' => 'success',
            'capture_recorded' => true,
            'decision' => $decision['decision'],
            'reason_code' => $decision['reason_code'],
            'order_payment_status' =>
                $decision['order_payment_status'],
        ];
    }

    private function assertLaunch(
        object $row,
        string $orderKey
    ): void {
        $hash = $this->requestHash(
            $orderKey,
            (string) $row->operation_key,
            (int) $row->amount_minor,
            CarbonImmutable::parse(
                $row->expires_at
            )->utc()->format('Y-m-d H:i:s.uP')
        );

        if (
            !hash_equals(
                (string) $row->request_sha256,
                $hash
            )
            || $row->currency_code !== 'UAH'
        ) {
            throw new DomainException(
                'Mono launch integrity mismatch'
            );
        }
    }

    private function requestHash(
        string $orderKey,
        string $operationKey,
        int $amount,
        string $expires
    ): string {
        return hash(
            'sha256',
            json_encode(
                [
                    $orderKey,
                    $operationKey,
                    $amount,
                    'UAH',
                    $expires,
                ],
                JSON_THROW_ON_ERROR
            )
        );
    }

    private function present(
        object $row,
        string $orderKey
    ): array {
        return [
            'order_key' => $orderKey,
            'operation_key' => (string) $row->operation_key,
            'invoice_id' => (string) $row->provider_invoice_id,
            'payment_url' => (string) $row->payment_url,
            'amount_minor' => (int) $row->amount_minor,
            'currency_code' => 'UAH',
            'status' => (string) $row->status,
            'money_captured' => false,
        ];
    }

    private function uuid(string $key): string
    {
        if (!Str::isUuid($key)) {
            throw new InvalidArgumentException(
                'Invalid Mono operation or order UUID'
            );
        }

        return strtolower($key);
    }

    private function enabled(): void
    {
        if (
            config('services.mono_acquiring.enabled') !== true
        ) {
            throw new DomainException(
                'Mono acquiring integration is disabled'
            );
        }
    }

    private function authorize(string $ability): void
    {
        $user = Auth::user();

        if (
            $user === null
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException(
                'Mono acquiring permission denied'
            );
        }
    }
}
