<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Conservative readiness report. Does NOT issue shipping permission.
 * External contract/settlement evidence cannot be supplied as booleans here.
 */
final class CheckoutCodReadinessRules
{
    public const VERSION = 'cod-carrier-readiness-v1';

    public const INTERNAL_CHECKS = [
        'order_pending',
        'checkout_active',
        'financial_snapshot_valid',
        'cod_selection_valid',
        'recipient_present',
        'manifest_valid',
        'package_within_limits',
        'measured_rate_present',
        'financial_preflight_within_limit',
        'stock_active',
    ];

    public const EXTERNAL_BLOCKERS = [
        'sender_account_not_proven_for_order',
        'cod_merchant_agreement_not_verified',
        'payout_contract_not_verified',
        'carrier_cod_acceptance_not_verified',
    ];

    /**
     * @param array<string, bool> $internalChecks
     * @return array<string, mixed>
     */
    public function evaluate(array $internalChecks): array
    {
        foreach ($internalChecks as $name => $value) {
            if (!in_array($name, self::INTERNAL_CHECKS, true) || !is_bool($value)) {
                throw new InvalidArgumentException('Unsupported COD readiness check');
            }
        }

        $checks = [];
        $blockers = [];

        foreach (self::INTERNAL_CHECKS as $name) {
            $checks[$name] = $internalChecks[$name] ?? false;

            if (!$checks[$name]) {
                $blockers[] = $name;
            }
        }

        $localComplete = $blockers === [];
        $blockers = array_merge($blockers, self::EXTERNAL_BLOCKERS);

        return [
            'ruleset' => self::VERSION,
            'checks' => $checks,
            'local_checks_complete' => $localComplete,
            'phase' => $localComplete
                ? 'awaiting_external_evidence'
                : 'awaiting_local_completion',
            'blockers' => $blockers,
            'carrier_cod_agreement_verified' => false,
            'payout_contract_verified' => false,
            'carrier_cod_acceptance_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
            'status' => 'blocked',
        ];
    }
}
