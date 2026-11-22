<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Exact documented Checkbox endpoints.
 * No retry(), no dynamic host.
 */
final class Fiscal62CheckboxClient
{
    private const HOST = 'https://api.checkbox.ua';

    public function post(array $payload): array
    {
        $response = $this->request()->post(
            self::HOST . '/api/v1/receipts/sell',
            $payload
        );

        return [
            'http_status' => $response->status(),
            'body' => $response->json(),
        ];
    }

    public function get(string $uuid): array
    {
        if (!Str::isUuid($uuid)) {
            throw new DomainException(
                'Invalid provider receipt UUID'
            );
        }

        $response = $this->request()->get(
            self::HOST . '/api/v1/receipts/' . $uuid
        );

        return [
            'http_status' => $response->status(),
            'body' => $response->json(),
        ];
    }

    private function request():
        \Illuminate\Http\Client\PendingRequest
    {
        $token = config('fiscal62.checkbox.token');

        if (
            !is_string($token)
            || trim($token) === ''
        ) {
            throw new DomainException(
                'Checkbox API token not configured'
            );
        }

        return Http::withToken($token)
            ->withHeaders([
                'X-Client-Name' => 'BOARDRIDERS',
                'X-Client-Version' => '62',
            ])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12);
    }
}
