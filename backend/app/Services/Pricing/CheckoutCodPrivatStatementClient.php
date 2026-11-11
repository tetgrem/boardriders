<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Read-only HTTPS client.
 * Host is fixed: no user-controlled URLs or SSRF.
 */
final class CheckoutCodPrivatStatementClient
{
    private const URL =
        'https://acp.privatbank.ua/api/statements/transactions';

    private const MAX_PAGES = 20;

    public function __construct(
        private readonly CheckoutCodPrivatStatementParser $parser
    ) {
    }

    /** @return array{token:string,iban:string,alias:string} */
    public function configuration(): array
    {
        $cfg = config(
            'services.privatbank_autoclient',
            []
        );

        if (
            !is_array($cfg)
            || ($cfg['enabled'] ?? false) !== true
        ) {
            throw new DomainException(
                'PrivatBank statement connector disabled'
            );
        }

        $token = $cfg['token'] ?? null;
        $iban = $cfg['account_iban'] ?? null;
        $alias = $cfg['account_alias'] ?? null;

        if (
            !is_string($token)
            || strlen($token) < 16
            || !is_string($iban)
            || !preg_match('/^UA[0-9]{27}$/D', $iban)
            || !is_string($alias)
            || !preg_match(
                '/^[a-z][a-z0-9_-]{0,47}$/D',
                $alias
            )
        ) {
            throw new DomainException(
                'PrivatBank connector credentials are not configured'
            );
        }

        return compact('token', 'iban', 'alias');
    }

    /** @return array{rows:array,received_count:int,skipped_count:int,page_count:int,result_sha256:string} */
    public function fetch(
        string $from,
        string $to,
        array $cfg
    ): array {
        $cursor = null;
        $visited = [];
        $rows = [];
        $received = 0;
        $skipped = 0;
        $pages = 0;

        do {
            if ($pages >= self::MAX_PAGES) {
                throw new DomainException(
                    'PrivatBank statement pagination safety limit'
                );
            }

            if ($pages !== 0) {
                // Keep at least one second between page requests.
                usleep(1100000);
            }

            $query = [
                'startDate' => date(
                    'd-m-Y',
                    strtotime($from)
                ),
                'endDate' => date(
                    'd-m-Y',
                    strtotime($to)
                ),
                'acc' => $cfg['iban'],
                'limit' => 100,
            ];

            if ($cursor !== null) {
                $query['followId'] = $cursor;
            }

            try {
                $response = Http::withHeaders([
                    'token' => $cfg['token'],
                ])
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->withoutRedirecting()
                    ->get(self::URL, $query);
            } catch (\Throwable $e) {
                throw new DomainException(
                    'PrivatBank statement transport unavailable'
                );
            }

            if (!$response->successful()) {
                throw new DomainException(
                    'PrivatBank statement HTTP request failed'
                );
            }

            $json = $response->json();

            if (!is_array($json)) {
                throw new DomainException(
                    'PrivatBank returned invalid JSON'
                );
            }

            try {
                $page = $this->parser->page(
                    $json,
                    $cfg['iban'],
                    $from,
                    $to
                );
            } catch (InvalidArgumentException $e) {
                throw new DomainException(
                    'PrivatBank response validation failed'
                );
            }

            $rows = array_merge(
                $rows,
                $page['rows']
            );

            $received += $page['received_count'];
            $skipped += $page['skipped_count'];
            ++$pages;

            if ($received > 2000) {
                throw new DomainException(
                    'PrivatBank transaction volume limit'
                );
            }

            $cursor = $page['next_page_id'];

            if ($page['has_more']) {
                if (isset($visited[$cursor])) {
                    throw new DomainException(
                        'PrivatBank repeated pagination cursor'
                    );
                }

                $visited[$cursor] = true;
            }
        } while ($page['has_more']);

        return [
            'rows' => $rows,
            'received_count' => $received,
            'skipped_count' => $skipped,
            'page_count' => $pages,
            'result_sha256' => hash(
                'sha256',
                json_encode(
                    $rows,
                    JSON_THROW_ON_ERROR
                )
            ),
        ];
    }
}
