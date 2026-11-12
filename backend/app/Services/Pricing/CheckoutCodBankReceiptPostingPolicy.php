<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** A posted bank receipt affects the bank/clearing ledger, not order payment state. */
final class CheckoutCodBankReceiptPostingPolicy
{
    /** @return list<array{side:string,account_code:string,amount_minor:int}> */
    public function lines(int $grossMinor, int $feeMinor, int $bankMinor): array
    {
        if ($grossMinor < 1 || $grossMinor > 1000000000
            || $feeMinor < 0 || $feeMinor > 1000000000
            || $bankMinor < 1 || $bankMinor > 100000000
            || $grossMinor !== $feeMinor + $bankMinor) {
            throw new InvalidArgumentException('Unbalanced COD bank receipt');
        }

        return [
            ['side' => 'debit', 'account_code' => 'cod_bank_asset', 'amount_minor' => $bankMinor],
            ['side' => 'credit', 'account_code' => 'cod_carrier_clearing', 'amount_minor' => $bankMinor],
        ];
    }
}
