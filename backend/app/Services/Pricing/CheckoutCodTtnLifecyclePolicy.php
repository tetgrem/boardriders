<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;

/** A carrier status is evidence about a TTN candidate, not proof of order delivery. */
final class CheckoutCodTtnLifecyclePolicy
{
    public function classify(?string $code): ?array
    {
        return match ($code) {
            '1' => ['state' => 'label_created', 'rank' => 10],
            '12' => ['state' => 'processing', 'rank' => 15],
            '4', '41', '5', '6', '101', '104', '111', '112' =>
            ['state' => 'in_transit', 'rank' => 20],
            '7', '8' => ['state' => 'awaiting_pickup', 'rank' => 30],
            '9', '10', '11' => ['state' => 'received', 'rank' => 40],
            '106' => ['state' => 'received_reverse_created', 'rank' => 40],
            '103', '108' => ['state' => 'refused', 'rank' => 40],
            '105' => ['state' => 'unclaimed', 'rank' => 40],
            '102' => ['state' => 'return_requested', 'rank' => 50],
            '2', '3' => ['state' => 'ttn_unverified', 'rank' => 5],
            default => null,
        };
    }

    /**
     * @param array{state:string,code:?string,at:?string,review:bool} $previous
     * @return array{decision:string,state:string,code:?string,at:?string,review:bool,action:string}
     */
    public function advance(
        array $previous,
        string $outcome,
        ?string $code,
        string $observedAt
    ): array {
        $state = $previous['state'];
        $effectiveCode = $previous['code'];
        $effectiveAt = $previous['at'];
        $review = $previous['review'];
        $decision = 'ignored_inconclusive';
        $target = $outcome === 'observed' ? $this->classify($code) : null;

        if ($outcome === 'provider_failure') {
            $decision = 'ignored_failure';
        } elseif ($outcome !== 'observed') {
            $decision = 'ignored_inconclusive';
        } elseif ($target === null) {
            $decision = 'ignored_unknown';
        } elseif ($effectiveAt !== null && CarbonImmutable::parse($observedAt)->lessThan(CarbonImmutable::parse($effectiveAt))) {
            $decision = 'ignored_stale';
        } else {
            $old = $this->classify($effectiveCode);
            $oldRank = $old['rank'] ?? 0;
            $newRank = $target['rank'];
            $oldFamily = $this->family($state);
            $newFamily = $this->family($target['state']);

            if (($oldFamily === 'received' && $newFamily === 'return')
                || ($oldFamily === 'return' && $newFamily === 'received')) {
                $decision = 'conflict';
                $review = true;
            } elseif ($oldFamily === 'received' && $newFamily === 'received'
                && $this->receiptRank($code) < $this->receiptRank($effectiveCode)) {
                $decision = 'ignored_regression';
            } elseif ($oldFamily === 'return' && $newFamily === 'return'
                && $state === 'return_requested' && $target['state'] !== 'return_requested') {
                $decision = 'ignored_regression';
            } elseif ($newRank < $oldRank) {
                $decision = 'ignored_regression';
            } elseif ($code === $effectiveCode) {
                $decision = 'unchanged';
            } else {
                $decision = 'advanced';
                $state = $target['state'];
                $effectiveCode = $code;
                $effectiveAt = CarbonImmutable::parse($observedAt)->utc()->format('Y-m-d\TH:i:s.u\Z');
                if ($code === '106') {
                    // Received + reverse document requires an explicit investigation.
                    $review = true;
                }
            }
        }

        return [
            'decision' => $decision,
            'state' => $state,
            'code' => $effectiveCode,
            'at' => $effectiveAt,
            'review' => $review,
            'action' => $this->action($state, $review),
        ];
    }

    private function family(string $state): string
    {
        return match ($state) {
            'received', 'received_reverse_created' => 'received',
            'refused', 'unclaimed', 'return_requested' => 'return',
            default => 'other',
        };
    }

    private function receiptRank(?string $code): int
    {
        return match ($code) {
            '9' => 1, '10', '106' => 2, '11' => 3, default => 0,
        };
    }

    public function action(string $state, bool $review): string
    {
        if ($review) {
            return 'manual_carrier_review';
        }
        return match ($state) {
            'received', 'received_reverse_created' => 'verify_cod_collection',
            'refused', 'unclaimed', 'return_requested' => 'follow_up_return',
            'awaiting_pickup' => 'contact_recipient',
            'ttn_unverified' => 'investigate_ttn',
            default => 'monitor',
        };
    }
}
