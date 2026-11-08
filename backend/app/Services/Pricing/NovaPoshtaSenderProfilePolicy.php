<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Locally configured references; no claim of carrier or contract verification. */
final class NovaPoshtaSenderProfilePolicy
{
    public function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D', $code)) {
            throw new InvalidArgumentException('Invalid sender profile code');
        }
        return $code;
    }

    public function uuid(string $value): string
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException('Invalid sender carrier reference');
        }
        return strtolower($value);
    }

    public function phone(string $phone): string
    {
        $phone = trim($phone);
        if (!preg_match('/^\+?380[0-9]{9}$/D', $phone)) {
            throw new InvalidArgumentException('Invalid Ukrainian sender phone');
        }
        return ltrim($phone, '+');
    }

    public function documentPayer(string $payer): string
    {
        if (!in_array($payer, ['Sender', 'Recipient'], true)) {
            throw new InvalidArgumentException('Invalid delivery payer');
        }
        return $payer;
    }

    public function documentPaymentMethod(string $method): string
    {
        if (!in_array($method, ['Cash', 'NonCash'], true)) {
            throw new InvalidArgumentException('Invalid delivery payment method');
        }
        return $method;
    }

    public function description(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) < 5 || mb_strlen($description) > 150
            || preg_match('/[\p{C}]/u', $description)
        ) {
            throw new InvalidArgumentException('Invalid parcel description');
        }
        return $description;
    }
}
