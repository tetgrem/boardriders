<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Pure classification of COD evidence. This class never authorizes payment. */
final class CheckoutCodOperationsPolicy
{
    private const KEYS = [
        'register_present', 'register_balanced', 'bank_balanced',
        'orders_balanced', 'financial_hold', 'refetch_anomaly',
        'reversal_reported', 'payment_status_conflict',
        'payment_method_conflict', 'ttn_candidate_missing',
        'ttn_candidate_ambiguous',
    ];

    /**
     * @param array<string,bool> $facts
     * @return array{
     *   status:string,
     *   priority:string,
     *   flags:list<string>,
     *   requires_attention:bool
     * }
     */
    public function evaluate(array $facts): array
    {
        if (array_keys($facts) !== self::KEYS) {
            throw new InvalidArgumentException(
                'COD operation facts are incomplete or unordered'
            );
        }

        foreach ($facts as $value) {
            if (!is_bool($value)) {
                throw new InvalidArgumentException(
                    'COD operation facts must be boolean'
                );
            }
        }

        $flags = [];

        if (!$facts['register_present']) {
            $flags[] = 'register_missing';
        } elseif (!$facts['register_balanced']) {
            $flags[] = 'register_totals_mismatch';
        }

        if (!$facts['bank_balanced']) {
            $flags[] = 'bank_amount_mismatch';
        }

        if (!$facts['orders_balanced']) {
            $flags[] = 'order_allocations_mismatch';
        }

        foreach ([
                     'financial_hold',
                     'refetch_anomaly',
                     'reversal_reported',
                     'payment_status_conflict',
                     'payment_method_conflict',
                     'ttn_candidate_missing',
                     'ttn_candidate_ambiguous',
                 ] as $name) {
            if ($facts[$name]) {
                $flags[] = $name;
            }
        }

        $critical = array_intersect($flags, [
                'register_totals_mismatch',
                'bank_amount_mismatch',
                'order_allocations_mismatch',
                'financial_hold',
                'refetch_anomaly',
                'reversal_reported',
                'payment_status_conflict',
                'payment_method_conflict',
            ]) !== [];

        $status = match (true) {
            $critical => 'investigation_required',
            !$facts['register_present'] => 'awaiting_register',
            $facts['ttn_candidate_ambiguous'] => 'ttn_investigation_required',
            default => 'awaiting_external_proof',
        };

        return [
            'status' => $status,
            'priority' => $critical
                ? 'critical'
                : ($flags !== [] ? 'normal' : 'low'),
            'flags' => $flags,
            'requires_attention' => $flags !== [],
        ];
    }
}
