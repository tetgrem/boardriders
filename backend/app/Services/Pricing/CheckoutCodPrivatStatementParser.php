<?php

namespace App\Services\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Strict posted-only projection of Privat24 Business AutoClient pages.
 */
final class CheckoutCodPrivatStatementParser
{
    /** @return array{rows:array,received_count:int,skipped_count:int,has_more:bool,next_page_id:?string} */
    public function page(
        array $data,
        string $iban,
        string $from,
        string $to
    ): array {
        if (
            ($data['status'] ?? null) !== 'SUCCESS'
            || ($data['type'] ?? null) !== 'transactions'
            || !isset($data['transactions'])
            || !is_array($data['transactions'])
            || !array_is_list($data['transactions'])
            || count($data['transactions']) > 100
        ) {
            throw new InvalidArgumentException(
                'Unexpected PrivatBank statement response'
            );
        }

        $hasMore = match ($data['exist_next_page'] ?? null) {
            true, 'true' => true,
            false, 'false' => false,
            default => throw new InvalidArgumentException(
                'Missing statement pagination state'
            ),
        };

        $next = $data['next_page_id'] ?? null;

        if (
            $hasMore
            && (
                !is_string($next)
                || !preg_match(
                    '/^[A-Za-z0-9_-]{1,100}$/D',
                    $next
                )
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid PrivatBank pagination cursor'
            );
        }

        $rows = [];
        $skipped = 0;

        foreach ($data['transactions'] as $raw) {
            if (
                !is_array($raw)
                || ($raw['AUT_MY_ACC'] ?? null) !== $iban
            ) {
                throw new InvalidArgumentException(
                    'Statement account mismatch'
                );
            }

            // Unposted or unreal operations are not accounting facts.
            if (
                ($raw['FL_REAL'] ?? null) !== 'r'
                || ($raw['PR_PR'] ?? null) !== 'r'
            ) {
                ++$skipped;
                continue;
            }

            if (
                ($raw['CCY'] ?? null) !== 'UAH'
                || !in_array(
                    $raw['TRANTYPE'] ?? null,
                    ['C', 'D'],
                    true
                )
                || !is_string($raw['REF'] ?? null)
                || !preg_match(
                    '/^[A-Za-z0-9_\/-]{1,100}$/D',
                    $raw['REF']
                )
                || !is_string($raw['REFN'] ?? null)
                || !preg_match(
                    '/^[A-Za-z0-9_\/-]{1,30}$/D',
                    $raw['REFN']
                )
            ) {
                throw new InvalidArgumentException(
                    'Unexpected posted transaction fields'
                );
            }

            $booked = $this->date(
                (string) ($raw['DAT_OD'] ?? '')
            );

            if ($booked < $from || $booked > $to) {
                throw new InvalidArgumentException(
                    'Statement transaction outside requested period'
                );
            }

            $amount = $this->minor($raw['SUM'] ?? null);

            $rows[] = [
                'ref' => $raw['REF'],
                'refn' => $raw['REFN'],
                'direction' => $raw['TRANTYPE'] === 'C'
                    ? 'credit'
                    : 'debit',
                'amount_minor' => $amount,
                'booked_on' => $booked,
            ];
        }

        return [
            'rows' => $rows,
            'received_count' => count($data['transactions']),
            'skipped_count' => $skipped,
            'has_more' => $hasMore,
            'next_page_id' => $hasMore ? $next : null,
        ];
    }

    public function minor(mixed $value): int
    {
        if (
            !is_string($value)
            || !preg_match(
                '/^-?(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D',
                $value
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid bank decimal amount'
            );
        }

        $value = ltrim($value, '-');

        [$units, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            ''
        );

        $minor = (int) $units * 100
            + (int) str_pad($fraction, 2, '0');

        if ($minor < 1 || $minor > 100000000) {
            throw new InvalidArgumentException(
                'Bank amount outside allowed range'
            );
        }

        return $minor;
    }

    private function date(string $raw): string
    {
        $date = DateTimeImmutable::createFromFormat(
            '!d.m.Y',
            $raw
        );

        if (
            !$date
            || $date->format('d.m.Y') !== $raw
        ) {
            throw new InvalidArgumentException(
                'Invalid statement posting date'
            );
        }

        return $date->format('Y-m-d');
    }
}
