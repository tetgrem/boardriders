<?php

namespace App\Services\Pricing;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only diagnostics for the API key's Sender counterparty listing.
 * Counterparty visibility is NOT proof of a COD agreement or payout contract.
 */
final class NovaPoshtaSenderAccountProbe
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 3;

    public function __construct(private readonly NovaPoshtaApiClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function inspect(string $expectedSenderRef): array
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D', $expectedSenderRef)) {
            throw new InvalidArgumentException(
                'Invalid sender counterparty reference'
            );
        }

        $matches = 0;
        $reportedSenderRole = false;
        $scanComplete = false;
        $pagesInspected = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $rows = $this->client->call(
                'Counterparty',
                'getCounterparties',
                [
                    'CounterpartyProperty' => 'Sender',
                    'Page' => $page,
                    'Limit' => self::PAGE_SIZE,
                ]
            );

            if (count($rows) > 500) {
                throw new RuntimeException(
                    'Unexpected sender directory response size'
                );
            }

            $pagesInspected++;

            foreach ($rows as $row) {
                if (
                    !is_array($row)
                    || !is_string($row['Ref'] ?? null)
                ) {
                    continue;
                }

                if (strcasecmp($row['Ref'], $expectedSenderRef) !== 0) {
                    continue;
                }

                $matches++;

                if (($row['CounterpartyProperty'] ?? null) === 'Sender') {
                    $reportedSenderRole = true;
                }
            }

            if (count($rows) < self::PAGE_SIZE) {
                $scanComplete = true;
                break;
            }
        }

        $result = match (true) {
            $matches > 1 => 'ambiguous_sender_reference',
            $matches === 1 && $reportedSenderRole => 'sender_role_reported',
            $matches === 1 => 'sender_ref_seen_role_unconfirmed',
            !$scanComplete => 'scan_incomplete',
            default => 'sender_ref_not_seen',
        };

        return [
            'result' => $result,
            'api_accessible' => true,
            'sender_ref_seen_once' => $matches === 1,
            'sender_role_reported' => $matches === 1 && $reportedSenderRole,
            'scan_complete' => $scanComplete,
            'pages_inspected' => $pagesInspected,
            'merchant_cod_agreement_verified' => false,
            'payout_contract_verified' => false,
            'carrier_cod_acceptance_verified' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
