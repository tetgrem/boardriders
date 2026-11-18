<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Only server-to-server acquiring calls.
 * No card data, no redirects to untrusted hosts.
 */
final class CheckoutMonoGateway53
{
    private const BASE = 'https://api.monobank.ua';

    private function token(): string
    {
        $token = config('services.mono_acquiring.token');

        if (!is_string($token) || trim($token) === '') {
            throw new DomainException('Mono merchant token is not configured');
        }

        return $token;
    }

    public function create(
        int $amountMinor,
        string $reference,
        int $validitySeconds
    ): array {
        if (
            $amountMinor <= 0
            || $validitySeconds < 60
            || $validitySeconds > 3600
        ) {
            throw new DomainException(
                'Invalid Mono invoice money or validity'
            );
        }

        $webhook = config('services.mono_acquiring.webhook_url');
        $redirect = config('services.mono_acquiring.redirect_url');

        foreach ([$webhook, $redirect] as $url) {
            if (
                !is_string($url)
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($url, PHP_URL_SCHEME))
                !== 'https'
                || parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null
            ) {
                throw new DomainException(
                    'Mono callback URLs must be configured HTTPS URLs'
                );
            }
        }

        $response = Http::withHeaders([
            'X-Token' => $this->token(),
        ])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(12)
            ->post(self::BASE . '/api/merchant/invoice/create', [
                'amount' => $amountMinor,
                'ccy' => 980,
                'merchantPaymInfo' => [
                    'reference' => $reference,
                    'destination' => 'BOARDRIDERS order',
                ],
                'webHookUrl' => $webhook,
                'redirectUrl' => $redirect,
                'validity' => $validitySeconds,
                'paymentType' => 'debit',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Mono invoice creation requires reconciliation'
            );
        }

        $body = $response->json();

        if (
            !is_array($body)
            || !is_string($body['invoiceId'] ?? null)
            || !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D',
                $body['invoiceId']
            )
            || !is_string($body['pageUrl'] ?? null)
        ) {
            throw new RuntimeException('Unexpected Mono invoice response');
        }

        $url = $body['pageUrl'];

        if (
            parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'pay.mbnk.biz'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || strlen($url) > 512
        ) {
            throw new RuntimeException(
                'Mono returned untrusted payment URL'
            );
        }

        return [
            'invoice_id' => $body['invoiceId'],
            'payment_url' => $url,
        ];
    }

    public function status(string $invoiceId): array
    {
        if (!preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D',
            $invoiceId
        )) {
            throw new DomainException('Invalid Mono invoice ID');
        }

        $response = Http::withHeaders([
            'X-Token' => $this->token(),
        ])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(12)
            ->get(
                self::BASE . '/api/merchant/invoice/status',
                ['invoiceId' => $invoiceId]
            );

        if (
            !$response->successful()
            || !is_array($response->json())
        ) {
            throw new RuntimeException(
                'Mono invoice status verification failed'
            );
        }

        return $response->json();
    }

    public function verifyWebhook(
        string $raw,
        ?string $signatureBase64
    ): bool {
        $keyBase64 = config(
            'services.mono_acquiring.public_key_base64'
        );

        if (
            !is_string($keyBase64)
            || $keyBase64 === ''
            || !is_string($signatureBase64)
            || $signatureBase64 === ''
            || strlen($raw) > 65536
        ) {
            return false;
        }

        $pem = base64_decode($keyBase64, true);
        $signature = base64_decode($signatureBase64, true);

        if ($pem === false || $signature === false) {
            return false;
        }

        $key = openssl_pkey_get_public($pem);

        return $key !== false
            && openssl_verify(
                $raw,
                $signature,
                $key,
                OPENSSL_ALGO_SHA256
            ) === 1;
    }
}
