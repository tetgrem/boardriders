<?php

namespace App\Services\Pricing;

/**
 * Exactly one read-only, first-page lookup. A negative result is not an
 * authoritative absence statement. No InternetDocument/save is invoked.
 */
final class NovaPoshtaTtnNumberProbe
{
    public function __construct(
        private readonly NovaPoshtaApiClient $client,
        private readonly NovaPoshtaTtnReconciliationPolicy $policy,
    ) {
    }

    /** @return array{result:string,rows_examined:int} */
    public function inspect(string $number): array
    {
        $number = $this->policy->number($number);
        $rows = $this->client->call('InternetDocument', 'getDocumentList', [
            'IntDocNumber' => $number,
            'Page' => 1,
            'Limit' => 100,
        ]);
        return $this->policy->classify($rows, $number);
    }
}
