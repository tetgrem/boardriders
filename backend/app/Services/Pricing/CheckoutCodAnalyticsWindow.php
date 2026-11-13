<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Inclusive UTC calendar dates, exclusive upper SQL timestamp boundary. */
final readonly class CheckoutCodAnalyticsWindow
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $endExclusive,
        public string $from,
        public string $to,
        public int $days,
    ) {
    }

    public static function make(
        ?string $from,
        ?string $to,
        ?CarbonImmutable $asOf = null
    ): self {
        $today = ($asOf ?? CarbonImmutable::now('UTC'))->utc()->startOfDay();
        $last = $to === null ? $today : self::date($to);
        $first = $from === null ? $last->subDays(29) : self::date($from);

        if ($first->greaterThan($last)) {
            throw new InvalidArgumentException('COD analytics start date is after end date');
        }

        $days = (int) $first->diff($last)->days + 1;
        if ($days > 90) {
            throw new InvalidArgumentException('COD analytics range must be 1 to 90 days');
        }

        return new self(
            $first,
            $last->addDay(),
            $first->toDateString(),
            $last->toDateString(),
            $days
        );
    }

    private static function date(string $value): CarbonImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new InvalidArgumentException('COD analytics date must be YYYY-MM-DD');
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $value, new DateTimeZone('UTC')
        );

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid COD analytics calendar date');
        }

        return CarbonImmutable::instance($date)->utc();
    }
}
