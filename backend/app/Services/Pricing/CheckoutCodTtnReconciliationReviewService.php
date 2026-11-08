<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Two-person review of a scan. Neither decision releases a safety hold. */
final class CheckoutCodTtnReconciliationReviewService
{
    public const REVIEW_ABILITY = 'nova-poshta.ttn-reconciliation.review';

    public function __construct(private readonly NovaPoshtaTtnReconciliationPolicy $policy)
    {
    }

    /** @return array<string, mixed> */
    public function review(
        string $scanOperationKey, string $reviewOperationKey,
        string $decision, string $reason,
    ): array {
        $actor = $this->actor();
        if (!Str::isUuid($scanOperationKey) || !Str::isUuid($reviewOperationKey)) {
            throw new InvalidArgumentException('Invalid reconciliation review UUID');
        }
        $scanOperationKey = strtolower($scanOperationKey);
        $reviewOperationKey = strtolower($reviewOperationKey);
        return DB::transaction(function () use (
            $actor, $scanOperationKey, $reviewOperationKey, $decision, $reason
        ): array {
            $scan = DB::table('checkout_cod_ttn_reconciliation_scans')
                ->where('operation_key', $scanOperationKey)->lockForUpdate()->first();
            if ($scan === null) {
                throw new DomainException('Reconciliation scan not found');
            }
            $this->policy->review($decision, $reason, (string) $scan->provider_result);
            if ((int) $scan->scanned_by === $actor) {
                throw new DomainException('Scanner cannot approve own reconciliation review');
            }
            $sha = $this->digest([
                'cod-ttn-reconciliation-review-v1', $scanOperationKey,
                $reviewOperationKey, $actor, $decision, $reason,
            ]);
            $existing = DB::table('checkout_cod_ttn_reconciliation_reviews')
                ->where('scan_id', $scan->id)->first();
            if ($existing !== null) {
                if ((string) $existing->operation_key !== $reviewOperationKey
                    || !hash_equals((string) $existing->request_hmac, $sha)) {
                    throw new DomainException('Reconciliation scan already reviewed');
                }
                return $this->present($existing);
            }
            if (DB::table('checkout_cod_ttn_reconciliation_reviews')
                ->where('operation_key', $reviewOperationKey)->exists()) {
                throw new DomainException('Review UUID belongs to another scan');
            }
            $id = DB::table('checkout_cod_ttn_reconciliation_reviews')->insertGetId([
                'operation_key' => $reviewOperationKey,
                'scan_id' => $scan->id,
                'decision' => $decision,
                'reason_code' => $reason,
                'reviewed_by' => $actor,
                'request_hmac' => $sha,
                'created_at' => now(),
            ]);
            return $this->present(DB::table('checkout_cod_ttn_reconciliation_reviews')
                ->where('id', $id)->first());
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::REVIEW_ABILITY)) {
            throw new AuthorizationException('TTN reconciliation review permission denied');
        }
        return (int) $id;
    }

    private function digest(array $payload): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('Application key missing');
        }
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'scan_id' => (int) $row->scan_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'reviewed_by' => (int) $row->reviewed_by,
            'manual_reconciliation_required' => true,
            'reconciliation_hold_released' => false,
            'waybill_linked_to_order' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
