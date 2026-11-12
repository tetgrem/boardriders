<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** A simulation only: each amount is expressed in UAH kopiykas. */
final class CheckoutCodJournalDraftPolicy
{
    /** @return list<array{entry_kind:string,debit_account:string,credit_account:string,amount_minor:int}> */
    public function entries(int $gross, int $fee, int $net): array
    {
        if ($gross < 1 || $gross > 100000000 || $fee < 0
            || $net < 1 || $fee > $gross || $gross - $fee !== $net) {
            throw new InvalidArgumentException('Invalid COD journal draft balance');
        }

        $entries = [
            [
                'entry_kind' => 'carrier_collection',
                'debit_account' => 'draft_carrier_clearing',
                'credit_account' => 'draft_cod_receivable',
                'amount_minor' => $gross,
            ],
            [
                'entry_kind' => 'bank_remittance',
                'debit_account' => 'draft_bank_receipt_suspense',
                'credit_account' => 'draft_carrier_clearing',
                'amount_minor' => $net,
            ],
        ];

        if ($fee > 0) {
            $entries[] = [
                'entry_kind' => 'carrier_fee',
                'debit_account' => 'draft_carrier_fee_expense',
                'credit_account' => 'draft_carrier_clearing',
                'amount_minor' => $fee,
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{entry_kind:string,debit_account:string,credit_account:string,amount_minor:int}> $entries
     * @return array{entries_count:int,total_debits_minor:int,total_credits_minor:int,carrier_clearing_balance_minor:int}
     */
    public function summarize(array $entries): array
    {
        $debits = 0;
        $credits = 0;
        $carrierBalance = 0;

        foreach ($entries as $entry) {
            $amount = $entry['amount_minor'];

            if (!is_int($amount) || $amount < 1 || $amount > 100000000
                || $entry['debit_account'] === $entry['credit_account']) {
                throw new InvalidArgumentException('Invalid COD journal draft entry');
            }

            $debits += $amount;
            $credits += $amount;

            if ($entry['debit_account'] === 'draft_carrier_clearing') {
                $carrierBalance += $amount;
            }

            if ($entry['credit_account'] === 'draft_carrier_clearing') {
                $carrierBalance -= $amount;
            }
        }

        return [
            'entries_count' => count($entries),
            'total_debits_minor' => $debits,
            'total_credits_minor' => $credits,
            'carrier_clearing_balance_minor' => $carrierBalance,
        ];
    }
}
