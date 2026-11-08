<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Two read-only calls. No raw provider fields escape this ephemeral comparison. */
final class NovaPoshtaTtnDocumentProbe
{
    public function __construct(
        private readonly NovaPoshtaApiClient $client,
        private readonly NovaPoshtaTtnReconciliationPolicy $numbers,
        private readonly NovaPoshtaTtnDocumentComparisonPolicy $policy,
    ) {
    }

    /**
     * @param array<string,mixed> $expected
     * @return array{summary:string,checks:array<string,string>,document_ref:?string}
     */
    public function inspect(string $number, array $expected): array
    {
        $number = $this->numbers->number($number);
        $list = $this->client->call('InternetDocument', 'getDocumentList', [
            'IntDocNumber' => $number, 'Page' => 1, 'Limit' => 100,
        ]);

        $count = count($list);
        $matches = [];
        $malformed = $count >= 100;
        foreach ($list as $row) {
            if (!is_array($row) || !is_string($row['IntDocNumber'] ?? null)
                || !preg_match('/^[0-9]{14}$/D', $row['IntDocNumber'])) {
                $malformed = true;
                continue;
            }
            if ($row['IntDocNumber'] === $number) {
                if (!is_string($row['Ref'] ?? null) || !Str::isUuid($row['Ref'])) {
                    $malformed = true;
                } else {
                    $matches[] = strtolower($row['Ref']);
                }
            }
        }
        if ($malformed || count($matches) !== 1) {
            return $this->result($this->policy->inconclusive('inconclusive_list'), null);
        }

        $ref = $matches[0];
        $documents = $this->client->call('InternetDocument', 'getDocument', ['Ref' => $ref]);
        if (count($documents) !== 1 || !is_array($documents[0])) {
            return $this->result($this->policy->inconclusive('inconclusive_document'), $ref);
        }
        $expected['Ref'] = $ref;
        return $this->result($this->policy->compare($documents[0], $expected), $ref);
    }

    /** @param array{summary:string,checks:array<string,string>} $result */
    private function result(array $result, ?string $ref): array
    {
        return $result + ['document_ref' => $ref];
    }
}
