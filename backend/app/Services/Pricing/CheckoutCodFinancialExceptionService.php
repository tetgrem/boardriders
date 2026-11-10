<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only incident / four-eyes decision for a financial claim.
 * Does NOT reverse money, mutate bank facts, or mark an order paid/refunded.
 */
final class CheckoutCodFinancialExceptionService
{
    public const REPORT_ABILITY = 'cod.financial-exception.report';
    public const REVIEW_ABILITY = 'cod.financial-exception.review';

    public function __construct(
        private readonly CheckoutCodFinancialExceptionPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function report(
        string $sourceKind,
        string $sourceKey,
        string $exceptionKey,
        string $reasonCode
    ): array {
        $actor = $this->actor(self::REPORT_ABILITY);

        $this->policy->assertReport($sourceKind, $reasonCode);

        if (!Str::isUuid($sourceKey) || !Str::isUuid($exceptionKey)) {
            throw new InvalidArgumentException(
                'Invalid financial exception UUID'
            );
        }

        $sourceKey = strtolower($sourceKey);
        $exceptionKey = strtolower($exceptionKey);

        return DB::transaction(function () use (
            $sourceKind,
            $sourceKey,
            $exceptionKey,
            $reasonCode,
            $actor
        ): array {
            $source = $this->source($sourceKind, $sourceKey);

            $signature = $this->hmac([
                'cod-financial-exception-v1',
                $sourceKind,
                $sourceKey,
                $exceptionKey,
                (int) $source->id,
                $reasonCode,
                $actor,
            ]);

            $old = DB::table('checkout_cod_financial_exceptions')
                ->where('exception_key', $exceptionKey)
                ->first();

            if ($old !== null) {
                if (
                    $old->source_kind !== $sourceKind
                    || (int) $old->source_id !== (int) $source->id
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $signature
                    )
                ) {
                    throw new DomainException(
                        'Exception UUID already used'
                    );
                }

                return $this->presentReport($old);
            }

            $last = DB::table('checkout_cod_financial_exceptions as e')
                ->leftJoin(
                    'checkout_cod_financial_exception_reviews as r',
                    'r.exception_id',
                    '=',
                    'e.id'
                )
                ->where('e.source_kind', $sourceKind)
                ->where('e.source_id', $source->id)
                ->orderByDesc('e.id')
                ->first(['r.decision']);

            if (
                $last !== null
                && $last->decision !== 'dismiss_report'
            ) {
                throw new DomainException(
                    'Source already has an active financial hold'
                );
            }

            $id = DB::table('checkout_cod_financial_exceptions')
                ->insertGetId([
                    'exception_key' => $exceptionKey,
                    'source_kind' => $sourceKind,
                    'source_id' => $source->id,
                    'reason_code' => $reasonCode,
                    'reported_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->presentReport(
                DB::table('checkout_cod_financial_exceptions')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(
        string $exceptionKey,
        string $reviewKey,
        string $decision,
        string $reasonCode
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);

        $this->policy->assertReview($decision, $reasonCode);

        if (
            !Str::isUuid($exceptionKey)
            || !Str::isUuid($reviewKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid financial exception review UUID'
            );
        }

        $exceptionKey = strtolower($exceptionKey);
        $reviewKey = strtolower($reviewKey);

        return DB::transaction(function () use (
            $exceptionKey,
            $reviewKey,
            $decision,
            $reasonCode,
            $actor
        ): array {
            $incident = DB::table('checkout_cod_financial_exceptions')
                ->where('exception_key', $exceptionKey)
                ->first();

            if ($incident === null) {
                throw new DomainException(
                    'Financial exception not found'
                );
            }

            // Lock source before reading reviews, same as report().
            $source = $this->source(
                (string) $incident->source_kind,
                (int) $incident->source_id
            );

            if (
                (int) $incident->reported_by === $actor
                || (int) $source->recorded_by === $actor
            ) {
                throw new DomainException(
                    'Independent financial reviewer required'
                );
            }

            if (
                $incident->source_kind === 'batch'
                && DB::table('checkout_cod_bank_batch_allocations')
                    ->where('batch_id', $source->id)
                    ->where('allocated_by', $actor)
                    ->exists()
            ) {
                throw new DomainException(
                    'Allocator cannot review batch incident'
                );
            }

            if (
                $incident->source_kind === 'single'
                && DB::table('checkout_cod_settlement_bank_matches')
                    ->where('bank_receipt_id', $source->id)
                    ->where('proposed_by', $actor)
                    ->exists()
            ) {
                throw new DomainException(
                    'Matcher cannot review bank incident'
                );
            }

            $signature = $this->hmac([
                'cod-financial-exception-review-v1',
                $exceptionKey,
                $reviewKey,
                $actor,
                $decision,
                $reasonCode,
                (string) $incident->request_hmac,
            ]);

            $old = DB::table('checkout_cod_financial_exception_reviews')
                ->where('exception_id', $incident->id)
                ->first();

            if ($old !== null) {
                if (
                    (string) $old->review_key !== $reviewKey
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $signature
                    )
                ) {
                    throw new DomainException(
                        'Financial exception already reviewed'
                    );
                }

                return $this->presentReview($old);
            }

            if (
                DB::table('checkout_cod_financial_exception_reviews')
                    ->where('review_key', $reviewKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Financial review UUID already used'
                );
            }

            $id = DB::table('checkout_cod_financial_exception_reviews')
                ->insertGetId([
                    'review_key' => $reviewKey,
                    'exception_id' => $incident->id,
                    'decision' => $decision,
                    'reason_code' => $reasonCode,
                    'reviewed_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->presentReview(
                DB::table('checkout_cod_financial_exception_reviews')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /**
     * Call only inside a transaction.
     * Lock a bank batch or the parent COD settlement case.
     */
    private function source(string $kind, string|int $key): object
    {
        $table = match ($kind) {
            'single' => 'checkout_cod_settlement_bank_receipts',
            'batch' => 'checkout_cod_bank_batches',
            'novapay' => 'checkout_cod_settlement_observations',
            default => throw new InvalidArgumentException(
                'Invalid exception source'
            ),
        };

        $column = is_int($key) ? 'id' : 'operation_key';

        $query = DB::table($table)->where($column, $key);

        if ($kind === 'batch') {
            $row = $query->lockForUpdate()
                ->first(['id', 'recorded_by']);
        } else {
            $row = $query->first([
                'id',
                'case_id',
                'recorded_by',
            ]);

            if ($row !== null) {
                $case = DB::table('checkout_cod_settlement_cases')
                    ->where('id', $row->case_id)
                    ->lockForUpdate()
                    ->first(['id']);

                if ($case === null) {
                    throw new DomainException(
                        'Settlement case missing'
                    );
                }
            }
        }

        if ($row === null) {
            throw new DomainException(
                'Financial exception source not found'
            );
        }

        return $row;
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException(
                'Financial exception permission denied'
            );
        }

        return (int) $id;
    }

    private function hmac(array $payload): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string,mixed> */
    private function presentReport(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'exception_key' => (string) $row->exception_key,
            'source_kind' => (string) $row->source_kind,
            'source_id' => (int) $row->source_id,
            'reason_code' => (string) $row->reason_code,
            'status_at_creation' => 'pending_review_hold',
            'funds_received_verified' => false,
            'cod_paid' => false,
            'refund_executed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'review_key' => (string) $row->review_key,
            'exception_id' => (int) $row->exception_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'funds_received_verified' => false,
            'cod_paid' => false,
            'refund_executed' => false,
        ];
    }
}
