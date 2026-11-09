<?php

namespace App\Services\Pricing;

/** Real read-only Nova Poshta status lookup. Never calls InternetDocument/save. */
final class NovaPoshtaTtnTrackingProbe
{
    public function __construct(
        private readonly NovaPoshtaApiClient $api,
        private readonly NovaPoshtaTtnTrackingPolicy $policy,
    ) {
    }

    /** @return array{outcome:string,status_code:?string} */
    public function inspect(string $number): array
    {
        $rows = $this->api->call(
            'TrackingDocument',
            'getStatusDocuments',
            [
                'Documents' => [
                    ['DocumentNumber' => $number],
                ],
            ]
        );

        return $this->policy->classify($rows, $number);
    }
}
