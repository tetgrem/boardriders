<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * One read-only Counterparty/getCounterpartyContactPersons call.
 * Observing a Ref in one response is NOT person identity verification.
 */
final class NovaPoshtaCounterpartyContactProbe
{
    public function __construct(private readonly NovaPoshtaApiClient $client)
    {
    }

    /** @return array{result: string, contact_seen: bool} */
    public function inspect(string $counterpartyRef, string $contactRef): array
    {
        if (!Str::isUuid($counterpartyRef) || !Str::isUuid($contactRef)) {
            throw new InvalidArgumentException('Invalid carrier contact lookup reference');
        }

        $rows = $this->client->call('Counterparty', 'getCounterpartyContactPersons', [
            'Ref' => strtolower($counterpartyRef),
        ]);

        if (count($rows) > 500) {
            throw new RuntimeException('Carrier contact response exceeds safety limit');
        }

        $matches = 0;
        $malformed = false;

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['Ref'] ?? null)
                || !Str::isUuid($row['Ref'])) {
                $malformed = true;
                continue;
            }

            if (strcasecmp($row['Ref'], $contactRef) === 0) {
                $matches++;
            }
        }

        // Incomplete/invalid rows prevent a reliable negative conclusion.
        $result = match (true) {
            $matches > 1 => 'duplicate_reference',
            $malformed => 'response_inconclusive',
            $matches === 1 => 'observed_once',
            default => 'not_observed_in_response',
        };

        return [
            'result' => $result,
            'contact_seen' => $result === 'observed_once',
        ];
    }
}
