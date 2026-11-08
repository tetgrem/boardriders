<?php

namespace App\Services\Pricing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NovaPoshtaApiClient
{
    private const ENDPOINT = 'https://api.novaposhta.ua/v2.0/json/';

    /** @return array<int, mixed> */
    public function call(string $model, string $method, array $properties): array
    {
        $key = config('services.nova_poshta.api_key');

        if (!is_string($key) || trim($key) === '') {
            throw new RuntimeException('Nova Poshta API key is not configured');
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->post(self::ENDPOINT, [
                    'apiKey' => $key,
                    'modelName' => $model,
                    'calledMethod' => $method,
                    'methodProperties' => $properties,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Nova Poshta connection failed', 0, $exception);
        }

        if (!$response->successful()) {
            throw new RuntimeException('Nova Poshta HTTP request failed');
        }

        $body = $response->json();

        if (
            !is_array($body)
            || ($body['success'] ?? null) !== true
            || !is_array($body['data'] ?? null)
            || !array_is_list($body['data'])
        ) {
            throw new RuntimeException('Invalid Nova Poshta response');
        }

        return $body['data'];
    }
}
