<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Pure operational coverage decision. It never approves financial evidence. */
final class CheckoutCodDailyMonitorPolicy
{
    /** @return array{status:string,severity:string,next_action:string,requires_attention:bool} */
    public function decide(bool $hasChanges, ?string $matchingCorrectionStatus, bool $otherPublished): array
    {
        if ($matchingCorrectionStatus !== null && !in_array(
                $matchingCorrectionStatus, ['proposed', 'approved', 'rejected', 'published'], true
            )) {
            throw new InvalidArgumentException('Invalid COD correction monitoring state');
        }

        if (!$hasChanges) {
            return $this->result('clean', 'none', 'none');
        }

        return match ($matchingCorrectionStatus) {
            'published' => $this->result('covered', 'none', 'none'),
            'approved' => $this->result('awaiting_publication', 'warning', 'publish'),
            'proposed' => $this->result('awaiting_review', 'warning', 'review'),
            'rejected' => $this->result('rejected_requires_action', 'critical', 'investigate'),
            null => $otherPublished
                ? $this->result('changed_after_publication', 'critical', 'propose_updated')
                : $this->result('correction_required', 'critical', 'propose'),
        };
    }

    /** @return array{status:string,severity:string,next_action:string,requires_attention:bool} */
    private function result(string $status, string $severity, string $action): array
    {
        return [
            'status' => $status,
            'severity' => $severity,
            'next_action' => $action,
            'requires_attention' => $severity !== 'none',
        ];
    }
}
