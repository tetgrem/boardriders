<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Parses only the Boardriders canonical CSV schema; does not authenticate NovaPay. */
final class CheckoutCodNovaPayRegisterCsvParser
{
    public const MAX_BYTES = 524288;
    public const MAX_ROWS = 1000;
    public const HEADERS = [
        'order_key', 'shipment_number', 'external_payment_ref',
        'gross_minor', 'fee_minor', 'net_minor',
    ];

    /**
     * @return array{document_sha256:string,rows:list<array<string,mixed>>,row_count:int}
     */
    public function parse(string $raw): array
    {
        $length = strlen($raw);
        if ($length < 2 || $length > self::MAX_BYTES
            || preg_match('//u', $raw) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
            throw new InvalidArgumentException('Invalid CSV size or encoding');
        }

        // Hash the exact original bytes, including BOM and CRLF.
        $sha256 = hash('sha256', $raw);
        $content = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
        $content = str_replace("\r\n", "\n", $content);
        if (str_contains($content, "\r")) {
            throw new InvalidArgumentException('Unsupported CSV line ending');
        }

        $stream = fopen('php://temp/maxmemory:524288', 'w+');
        if ($stream === false) {
            throw new InvalidArgumentException('CSV stream unavailable');
        }

        try {
            if (fwrite($stream, $content) !== strlen($content) || !rewind($stream)) {
                throw new InvalidArgumentException('CSV stream write failed');
            }

            $header = fgetcsv($stream, 0, ',', '"', '');
            if ($header !== self::HEADERS) {
                throw new InvalidArgumentException('Unknown canonical CSV header');
            }

            $rows = [];

            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if (!is_array($values) || count($values) !== count(self::HEADERS)
                    || count($rows) >= self::MAX_ROWS) {
                    throw new InvalidArgumentException('Invalid CSV row shape or count');
                }

                foreach ($values as $value) {
                    if (!is_string($value) || strlen($value) > 128
                        || str_contains($value, "\n") || str_contains($value, "\r")) {
                        throw new InvalidArgumentException('Invalid CSV field');
                    }
                }

                $line = array_combine(self::HEADERS, $values);

                if (!Str::isUuid($line['order_key'])
                    || !preg_match('/^[0-9]{14}$/D', $line['shipment_number'])
                    || !preg_match('/^[A-Za-z0-9_-]{6,80}$/D', $line['external_payment_ref'])) {
                    throw new InvalidArgumentException('Invalid CSV order or external reference');
                }

                $line['order_key'] = strtolower($line['order_key']);

                foreach (['gross_minor', 'fee_minor', 'net_minor'] as $field) {
                    $number = $line[$field];

                    if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $number)
                        || (strlen($number) === 10 && strcmp($number, '1000000000') > 0)) {
                        throw new InvalidArgumentException(
                            'CSV monetary fields must be integer kopiykas'
                        );
                    }

                    $line[$field] = (int) $number;
                }

                $rows[] = $line;
            }

            if ($rows === []) {
                throw new InvalidArgumentException('CSV contains no register rows');
            }

            return [
                'document_sha256' => $sha256,
                'row_count' => count($rows),
                'rows' => $rows,
            ];
        } finally {
            fclose($stream);
        }
    }
}
