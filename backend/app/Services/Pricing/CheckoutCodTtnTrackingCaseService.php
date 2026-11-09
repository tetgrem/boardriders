<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Registers only independently compared historical TTN candidates. */
final class CheckoutCodTtnTrackingCaseService
{
    public const START_ABILITY = 'nova-poshta.ttn-tracking.start';

    /** @return array<string,mixed> */
    public function start(
        string $comparisonKey,
        string $trackingKey
    ): array {
        $actor = $this->actor();

        if (
            !Str::isUuid($comparisonKey)
            || !Str::isUuid($trackingKey)
        ) {
            throw new InvalidArgumentException('Invalid tracking UUID');
        }

        $comparisonKey = strtolower($comparisonKey);
        $trackingKey = strtolower($trackingKey);

        return DB::transaction(function () use (
            $actor,
            $comparisonKey,
            $trackingKey
        ): array {
            $source = $this->source($comparisonKey);

            if ($source === null) {
                throw new DomainException(
                    'Independent matching TTN comparison required'
                );
            }

            DB::table('checkout_cod_ttn_submission_intents')
                ->where('id', $source->intent_id)
                ->lockForUpdate()
                ->first(['id']);

            $signature = $this->digest([
                'cod-tracking-case-v1',
                $comparisonKey,
                $trackingKey,
                $actor,
                (int) $source->intent_id,
                (int) $source->comparison_id,
                (string) $source->candidate_number_hmac,
                (string) $source->intent_context_hmac,
            ]);

            $existing = DB::table('checkout_cod_ttn_tracking_cases')
                ->where('tracking_key', $trackingKey)
                ->first();

            if ($existing !== null) {
                if (
                    (int) $existing->comparison_id
                    !== (int) $source->comparison_id
                    || (int) $existing->started_by !== $actor
                    || !hash_equals(
                        (string) $existing->request_hmac,
                        $signature
                    )
                ) {
                    throw new DomainException(
                        'Tracking key reused for another request'
                    );
                }

                return $this->present($existing);
            }

            if (
                DB::table('checkout_cod_ttn_tracking_cases')
                    ->where('intent_id', $source->intent_id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Tracking candidate already registered for this intent'
                );
            }

            $id = DB::table('checkout_cod_ttn_tracking_cases')
                ->insertGetId([
                    'tracking_key' => $trackingKey,
                    'intent_id' => $source->intent_id,
                    'comparison_id' => $source->comparison_id,
                    'scan_id' => $source->scan_id,
                    'candidate_number_hmac' =>
                        $source->candidate_number_hmac,
                    'intent_context_hmac' =>
                        $source->intent_context_hmac,
                    'started_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_ttn_tracking_cases')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    private function source(string $comparisonKey): ?object
    {
        return DB::table('checkout_cod_ttn_document_comparisons as c')
            ->join(
                'checkout_cod_ttn_reconciliation_scans as s',
                's.id',
                '=',
                'c.scan_id'
            )
            ->join(
                'checkout_cod_ttn_reconciliation_reviews as r',
                'r.id',
                '=',
                'c.review_id'
            )
            ->join(
                'checkout_cod_ttn_submission_intents as i',
                'i.id',
                '=',
                'c.intent_id'
            )
            ->where('c.operation_key', $comparisonKey)
            ->where('c.summary', 'snapshot_consistent')
            ->whereNotNull('c.document_ref_hmac')
            ->where('s.provider_result', 'number_observed_once')
            ->where('r.decision', 'escalate_to_carrier')
            ->where('r.reason_code', 'candidate_seen')
            ->whereColumn('s.intent_id', 'c.intent_id')
            ->whereColumn('r.scan_id', 's.id')
            ->whereColumn(
                'c.candidate_number_hmac',
                's.candidate_number_hmac'
            )
            ->whereColumn(
                'c.intent_context_hmac',
                's.intent_context_hmac'
            )
            ->whereColumn('c.intent_context_hmac', 'i.context_hmac')
            ->first([
                'c.id as comparison_id',
                'c.scan_id',
                'c.intent_id',
                'c.candidate_number_hmac',
                'c.intent_context_hmac',
            ]);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(self::START_ABILITY)
        ) {
            throw new AuthorizationException(
                'Tracking registration denied'
            );
        }

        return (int) $id;
    }

    private function digest(array $data): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'tracking_key' => (string) $row->tracking_key,
            'intent_id' => (int) $row->intent_id,
            'comparison_id' => (int) $row->comparison_id,
            'number_is_only_candidate' => true,
            'carrier_ownership_verified' => false,
            'delivery_confirmed_by_tracking' => false,
            'cod_paid' => false,
            'ttn_submission_allowed' => false,
        ];
    }
}
