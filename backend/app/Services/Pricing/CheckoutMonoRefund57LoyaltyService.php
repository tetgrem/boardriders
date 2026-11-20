<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Loyalty\LoyaltyAdjustmentService;
use App\Services\Loyalty\LoyaltyRedemptionRefundService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class CheckoutMonoRefund57LoyaltyService
{
    public const APPROVE_ABILITY = 'payments.mono.refunds.loyalty.approve';
    public const POST_ABILITY = 'payments.mono.refunds.loyalty.post';

    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly LoyaltyAdjustmentService $adjustments,
        private readonly LoyaltyRedemptionRefundService $redemptions,
    ) {
    }

    /**
     * @param array<int,int> $unitsByGrant
     * @param array<int,string> $expiryOverrides
     * @return array<string,mixed>
     */
    public function approve(
        int $journalId,
        string $kind,
        int $walletId,
        int $sourceId,
        array $unitsByGrant,
        string $calculationSha256,
        array $expiryOverrides = [],
    ): array {
        $actor = $this->actor(self::APPROVE_ABILITY);
        $this->enabled();

        if (
            $journalId <= 0
            || $walletId <= 0
            || $sourceId <= 0
            || !in_array($kind, ['reverse_earned', 'restore_spent'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $calculationSha256)
        ) {
            throw new DomainException('Invalid loyalty refund approval');
        }

        [$allocations, $expiry] = $this->normalize(
            $kind,
            $sourceId,
            $unitsByGrant,
            $expiryOverrides
        );

        return DB::transaction(function () use (
            $actor,
            $journalId,
            $walletId,
            $sourceId,
            $kind,
            $allocations,
            $expiry,
            $calculationSha256
        ): array {
            $context = $this->context($journalId, true);

            $journal = $context['journal'];
            $execution = $context['execution'];
            $refundPlan = $context['refund_plan'];
            $orderKey = $context['order_key'];

            if (in_array($actor, [
                (int) $journal->recorded_by,
                (int) $execution->executed_by,
                (int) $refundPlan->planned_by,
            ], true)) {
                throw new DomainException(
                    'Loyalty reviewer must be independent of refund operators'
                );
            }

            $wallet = DB::table('loyalty_wallets')
                ->where('id', $walletId)
                ->lockForUpdate()
                ->first();

            if (
                $wallet === null
                || (int) $wallet->market_id !== (int) $context['market_id']
            ) {
                throw new DomainException(
                    'Loyalty wallet belongs to another market'
                );
            }

            $this->validateSource(
                $kind,
                $sourceId,
                $walletId,
                (int) $wallet->market_id,
                $orderKey,
                $context['preparation'],
                $allocations
            );

            $signed = $this->planData(
                $journalId,
                $walletId,
                $kind,
                $sourceId,
                $allocations,
                $expiry,
                $calculationSha256,
                $actor
            );

            $existing = DB::table(
                'checkout_mono_refund_loyalty_plans57'
            )
                ->where('journal_id', $journalId)
                ->where('effect_kind', $kind)
                ->where('source_id', $sourceId)
                ->first();

            if ($existing !== null) {
                $this->verifyPlan($existing);

                if (!hash_equals(
                    (string) $existing->plan_hmac,
                    $this->signer->digest($signed)
                )) {
                    throw new DomainException(
                        'Bonus allocation already approved with different terms'
                    );
                }

                return $this->presentPlan($existing);
            }

            $id = (int) DB::table(
                'checkout_mono_refund_loyalty_plans57'
            )->insertGetId([
                ...$signed,
                'allocations' => json_encode(
                    $allocations,
                    JSON_THROW_ON_ERROR
                ),
                'expiry_overrides' => json_encode(
                    (object) $expiry,
                    JSON_THROW_ON_ERROR
                ),
                'plan_hmac' => $this->signer->digest($signed),
                'created_at' => now(),
            ]);

            return $this->presentPlan(
                DB::table('checkout_mono_refund_loyalty_plans57')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function post(int $planId): array
    {
        $actor = $this->actor(self::POST_ABILITY);
        $this->enabled();

        if ($planId <= 0) {
            throw new DomainException('Invalid loyalty plan ID');
        }

        return DB::transaction(function () use (
            $planId,
            $actor
        ): array {
            $locator = DB::table(
                'checkout_mono_refund_loyalty_plans57'
            )
                ->where('id', $planId)
                ->first(['journal_id']);

            if ($locator === null) {
                throw new DomainException(
                    'Approved loyalty plan not found'
                );
            }

            $context = $this->context(
                (int) $locator->journal_id,
                true
            );

            $plan = DB::table(
                'checkout_mono_refund_loyalty_plans57'
            )
                ->where('id', $planId)
                ->lockForUpdate()
                ->first();

            $this->verifyPlan($plan);

            if (in_array($actor, [
                (int) $plan->approved_by,
                (int) $context['journal']->recorded_by,
                (int) $context['execution']->executed_by,
                (int) $context['refund_plan']->planned_by,
            ], true)) {
                throw new DomainException(
                    'Loyalty poster must be independent of reviewer and journaler'
                );
            }

            $existing = DB::table(
                'checkout_mono_refund_loyalty_postings57'
            )
                ->where('plan_id', $planId)
                ->first();

            if ($existing !== null) {
                return $this->presentPosting($existing, $plan);
            }

            $wallet = DB::table('loyalty_wallets')
                ->where('id', $plan->wallet_id)
                ->lockForUpdate()
                ->first();

            if (
                $wallet === null
                || (int) $wallet->market_id !== (int) $context['market_id']
            ) {
                throw new DomainException(
                    'Loyalty market changed since approval'
                );
            }

            $allocations = $this->decodeAllocations(
                (string) $plan->allocations
            );

            $expiry = $this->decodeExpiry(
                (string) $plan->expiry_overrides
            );

            $this->validateSource(
                (string) $plan->effect_kind,
                (int) $plan->source_id,
                (int) $plan->wallet_id,
                (int) $wallet->market_id,
                $context['order_key'],
                $context['preparation'],
                $allocations
            );

            $operationKey = $this->operationKey($plan);
            $reference = 'mono57:journal:' . (int) $plan->journal_id;

            if ($plan->effect_kind === 'reverse_earned') {
                $result = $this->adjustments->reverseGrant(
                    (int) $plan->wallet_id,
                    (int) $plan->source_id,
                    (int) $allocations[0]['units'],
                    $operationKey,
                    'paid_order_return',
                    $reference,
                    [
                        'journal_id' => (int) $plan->journal_id,
                        'allocation_sha256' =>
                            (string) $plan->calculation_sha256,
                    ]
                );
            } else {
                $restored = [];

                foreach ($allocations as $allocation) {
                    $restored[$allocation['grant_id']] =
                        $allocation['units'];
                }

                $extensions = [];

                foreach ($expiry as $grantId => $isoDate) {
                    $extensions[(int) $grantId] =
                        CarbonImmutable::parse($isoDate)->utc();
                }

                $result = $this->redemptions->refundCapturedBonuses(
                    (int) $plan->wallet_id,
                    (int) $plan->source_id,
                    $operationKey,
                    $reference,
                    $restored,
                    $extensions,
                    [
                        'journal_id' => (int) $plan->journal_id,
                        'allocation_sha256' =>
                            (string) $plan->calculation_sha256,
                    ]
                );
            }

            $operationId = $result['operation_id'] ?? null;

            if (
                !is_string($operationId)
                || !\Illuminate\Support\Str::isUuid($operationId)
            ) {
                throw new RuntimeException(
                    'Loyalty service did not record an operation UUID'
                );
            }

            /*
             * BLOCK 57 FIX:
             *
             * loyalty_operations.result is JSONB in PostgreSQL.
             * PostgreSQL can change JSON object key ordering.
             *
             * Compare normalized values instead of serialized
             * PHP array key order.
             */
            $stored = DB::table('loyalty_operations')
                ->where('id', $operationId)
                ->where('wallet_id', $plan->wallet_id)
                ->where('operation_key', $operationKey)
                ->first(['result']);

            if ($stored === null) {
                throw new RuntimeException(
                    'Loyalty service did not persist its operation'
                );
            }

            $storedResult = json_decode(
                (string) $stored->result,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($storedResult)
                || !hash_equals(
                    $this->resultHash($result),
                    $this->resultHash($storedResult)
                )
            ) {
                throw new RuntimeException(
                    'Loyalty service result differs from stored operation'
                );
            }

            $posting = [
                'plan_id' => $planId,
                'loyalty_operation_id' => $operationId,
                'posted_by' => $actor,
                'result_sha256' => $this->resultHash($storedResult),
            ];

            $id = (int) DB::table(
                'checkout_mono_refund_loyalty_postings57'
            )->insertGetId([
                ...$posting,
                'posting_hmac' => $this->signer->digest($posting),
                'created_at' => now(),
            ]);

            return $this->presentPosting(
                DB::table('checkout_mono_refund_loyalty_postings57')
                    ->where('id', $id)
                    ->first(),
                $plan
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function show(int $planId): array
    {
        $this->actor(self::POST_ABILITY);

        $plan = DB::table(
            'checkout_mono_refund_loyalty_plans57'
        )
            ->where('id', $planId)
            ->first();

        $this->verifyPlan($plan);

        $posting = DB::table(
            'checkout_mono_refund_loyalty_postings57'
        )
            ->where('plan_id', $planId)
            ->first();

        return $posting === null
            ? $this->presentPlan($plan)
            : $this->presentPosting($posting, $plan);
    }

    /**
     * @return array{
     *   journal:object,
     *   execution:object,
     *   refund_plan:object,
     *   preparation:object,
     *   order_key:string,
     *   market_id:int
     * }
     */
    private function context(int $journalId, bool $lockOrder): array
    {
        $journal = DB::table('checkout_mono_refund_journal56')
            ->where('id', $journalId)
            ->first();

        if ($journal === null) {
            throw new DomainException(
                'Verified Mono refund journal not found'
            );
        }

        $execution = DB::table(
            'checkout_mono_refund_executions'
        )
            ->where('id', $journal->execution_id)
            ->first();

        $refundPlan = DB::table(
            'checkout_mono_rma_refund_plans'
        )
            ->where('id', $journal->refund_plan_id)
            ->first();

        if (
            $execution === null
            || $refundPlan === null
            || $execution->status !== 'succeeded'
            || (int) $execution->refund_plan_id
            !== (int) $journal->refund_plan_id
            || (int) $execution->order_id
            !== (int) $journal->order_id
            || (int) $execution->amount_minor
            !== (int) $journal->amount_minor
        ) {
            throw new DomainException(
                'Mono refund is not confirmed'
            );
        }

        $this->assertJournal($journal, $execution);

        $orderQuery = DB::table('orders')
            ->where('id', $journal->order_id);

        $order = (
        $lockOrder
            ? $orderQuery->lockForUpdate()
            : $orderQuery
        )->first([
                'id',
                'order_key',
                'payment_status',
            ]);

        if (
            $order === null
            || $order->payment_status !== 'paid'
        ) {
            throw new DomainException(
                'Paid original order required'
            );
        }

        $prep = DB::table('checkout_order_preparations')
            ->where('order_id', $order->id)
            ->first();

        if ($prep === null) {
            throw new DomainException(
                'Original checkout preparation missing'
            );
        }

        $handoff = DB::table(
            'checkout_mono_refund_handoffs56'
        )
            ->where('journal_id', $journalId)
            ->where('topic', 'loyalty_review')
            ->first();

        $key = hash(
            'sha256',
            'mono-refund-journal56:'
            . (string) $execution->operation_key
            . ':loyalty_review'
        );

        if (
            $handoff === null
            || !hash_equals(
                $key,
                (string) $handoff->event_key
            )
            || !hash_equals(
                (string) $handoff->event_hmac,
                $this->signer->digest([
                    'journal_id' => $journalId,
                    'topic' => 'loyalty_review',
                    'event_key' => $key,
                ])
            )
        ) {
            throw new RuntimeException(
                'Loyalty review handoff signature mismatch'
            );
        }

        return [
            'journal' => $journal,
            'execution' => $execution,
            'refund_plan' => $refundPlan,
            'preparation' => $prep,
            'order_key' => (string) $order->order_key,
            'market_id' => (int) $prep->market_id,
        ];
    }

    private function assertJournal(
        object $journal,
        object $execution
    ): void {
        $data = [
            'execution_id' => (int) $journal->execution_id,
            'refund_plan_id' => (int) $journal->refund_plan_id,
            'order_id' => (int) $journal->order_id,
            'capture_receipt_id' =>
                (int) $journal->capture_receipt_id,
            'observation_id' => (int) $journal->observation_id,
            'ext_ref' => (string) $journal->ext_ref,
            'amount_minor' => (int) $journal->amount_minor,
            'currency_code' => (string) $journal->currency_code,
            'recorded_by' => (int) $journal->recorded_by,
        ];

        if (
            !hash_equals(
                (string) $journal->entry_hmac,
                $this->signer->digest($data)
            )
            || $journal->ext_ref !== $execution->ext_ref
            || $journal->capture_receipt_id
            !== $execution->capture_receipt_id
        ) {
            throw new RuntimeException(
                'Mono journal identity signature mismatch'
            );
        }

        $observation = DB::table(
            'checkout_mono_refund_observations'
        )
            ->where('id', $journal->observation_id)
            ->first();

        if (
            $observation === null
            || (int) $observation->execution_id
            !== (int) $execution->id
            || $observation->source !== 'invoice_status'
            || $observation->state !== 'success'
            || (int) $observation->matched_amount_minor
            !== (int) $execution->amount_minor
        ) {
            throw new RuntimeException(
                'Original provider success evidence missing'
            );
        }
    }

    /**
     * @param array<int,array{grant_id:int,units:int}> $allocations
     */
    private function validateSource(
        string $kind,
        int $sourceId,
        int $walletId,
        int $marketId,
        string $orderKey,
        object $prep,
        array $allocations
    ): void {
        if ($kind === 'reverse_earned') {
            $grant = DB::table('loyalty_grants')
                ->where('id', $sourceId)
                ->where('wallet_id', $walletId)
                ->where('market_id', $marketId)
                ->where('grant_type', 'purchase')
                ->where('source_ref', $orderKey)
                ->first();

            if ($grant === null) {
                throw new DomainException(
                    'Purchase grant is not linked to the refunded order'
                );
            }

            if (
                count($allocations) !== 1
                || $allocations[0]['grant_id'] !== $sourceId
                || $allocations[0]['units']
                > (int) $grant->units_total
            ) {
                throw new DomainException(
                    'Purchase bonus reversal is not attributable to grant'
                );
            }

            return;
        }

        if ($kind !== 'restore_spent') {
            throw new DomainException(
                'Invalid bonus effect type'
            );
        }

        if (
            $prep->loyalty_wallet_id === null
            || $prep->loyalty_reservation_id === null
            || (int) $prep->loyalty_wallet_id !== $walletId
            || (int) $prep->loyalty_reservation_id !== $sourceId
        ) {
            throw new DomainException(
                'Captured bonus reservation not linked to checkout'
            );
        }

        $reservation = DB::table('loyalty_reservations')
            ->where('id', $sourceId)
            ->where('wallet_id', $walletId)
            ->where('market_id', $marketId)
            ->where('order_reference', $orderKey)
            ->first();

        if ($reservation === null) {
            throw new DomainException(
                'Bonus reservation belongs to different order'
            );
        }

        $sum = 0;

        foreach ($allocations as $allocation) {
            $line = DB::table('loyalty_reservation_lines')
                ->where('reservation_id', $sourceId)
                ->where('wallet_id', $walletId)
                ->where('market_id', $marketId)
                ->where('grant_id', $allocation['grant_id'])
                ->first(['units']);

            if (
                $line === null
                || $allocation['units'] > (int) $line->units
            ) {
                throw new DomainException(
                    'Refund grant allocation exceeds reservation line'
                );
            }

            $sum += $allocation['units'];
        }

        if (
            $sum > (int) $reservation->units_total
            || $sum > (int) $prep->bonus_units_reserved
        ) {
            throw new DomainException(
                'Bonus restoration exceeds the original checkout'
            );
        }
    }

    /**
     * @param array<int,int> $unitsByGrant
     * @param array<int,string> $expiryOverrides
     * @return array{0:array<int,array{grant_id:int,units:int}>,1:array<string,string>}
     */
    private function normalize(
        string $kind,
        int $sourceId,
        array $unitsByGrant,
        array $expiryOverrides
    ): array {
        if (
            $unitsByGrant === []
            || count($unitsByGrant) > 200
        ) {
            throw new DomainException(
                'Missing or excessive bonus allocations'
            );
        }

        $rows = [];

        foreach ($unitsByGrant as $grantId => $units) {
            if (
                !is_int($grantId)
                || $grantId <= 0
                || !is_int($units)
                || $units <= 0
            ) {
                throw new DomainException(
                    'Bonus allocations require positive integer grant IDs and units'
                );
            }

            $rows[] = [
                'grant_id' => $grantId,
                'units' => $units,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                $a['grant_id'] <=> $b['grant_id']
        );

        if (
            $kind === 'reverse_earned'
            && (
                count($rows) !== 1
                || $rows[0]['grant_id'] !== $sourceId
            )
        ) {
            throw new DomainException(
                'Earned grant reversal must target its original grant'
            );
        }

        if (
            $kind === 'reverse_earned'
            && $expiryOverrides !== []
        ) {
            throw new DomainException(
                'Earned grant reversal has no expiry overrides'
            );
        }

        $expiry = [];

        foreach ($expiryOverrides as $grantId => $value) {
            if (
                !is_int($grantId)
                || !is_string($value)
                || !in_array(
                    $grantId,
                    array_column($rows, 'grant_id'),
                    true
                )
            ) {
                throw new DomainException(
                    'Expiry overrides must refer to restored bonus grants'
                );
            }

            try {
                $date = CarbonImmutable::parse($value)->utc();
            } catch (\Throwable) {
                throw new DomainException(
                    'Invalid bonus expiry timestamp'
                );
            }

            if (
                $date->lessThanOrEqualTo(
                    CarbonImmutable::now('UTC')
                )
            ) {
                throw new DomainException(
                    'Restored bonus expiry must be in future'
                );
            }

            $expiry[(string) $grantId] =
                $date->toIso8601String();
        }

        ksort($expiry, SORT_NUMERIC);

        return [$rows, $expiry];
    }

    /**
     * @return array<int,array{grant_id:int,units:int}>
     */
    private function decodeAllocations(string $json): array
    {
        $rows = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($rows)
            || !array_is_list($rows)
            || $rows === []
        ) {
            throw new RuntimeException(
                'Stored bonus allocations invalid'
            );
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (
                !is_array($row)
                || count($row) !== 2
                || !is_int($row['grant_id'] ?? null)
                || !is_int($row['units'] ?? null)
                || $row['grant_id'] <= 0
                || $row['units'] <= 0
            ) {
                throw new RuntimeException(
                    'Stored bonus allocation invalid'
                );
            }

            $normalized[] = [
                'grant_id' => $row['grant_id'],
                'units' => $row['units'],
            ];
        }

        if (
            $normalized !== collect($normalized)
                ->sortBy('grant_id')
                ->values()
                ->all()
            || count(array_unique(
                array_column($normalized, 'grant_id')
            )) !== count($normalized)
        ) {
            throw new RuntimeException(
                'Stored bonus allocations not canonical'
            );
        }

        return $normalized;
    }

    /** @return array<string,string> */
    private function decodeExpiry(string $json): array
    {
        $dates = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($dates)) {
            throw new RuntimeException(
                'Stored expiry overrides invalid'
            );
        }

        foreach ($dates as $key => $value) {
            if (
                !ctype_digit((string) $key)
                || !is_string($value)
            ) {
                throw new RuntimeException(
                    'Stored expiry override invalid'
                );
            }
        }

        ksort($dates, SORT_NUMERIC);

        return $dates;
    }

    /**
     * @param array<int,array{grant_id:int,units:int}> $allocations
     * @param array<string,string> $expiry
     * @return array<string,mixed>
     */
    private function planData(
        int $journalId,
        int $walletId,
        string $kind,
        int $sourceId,
        array $allocations,
        array $expiry,
        string $calculationHash,
        int $actor
    ): array {
        return [
            'journal_id' => $journalId,
            'wallet_id' => $walletId,
            'effect_kind' => $kind,
            'source_id' => $sourceId,
            'allocations' => $allocations,
            'expiry_overrides' => (object) $expiry,
            'calculation_sha256' => $calculationHash,
            'approved_by' => $actor,
        ];
    }

    private function verifyPlan(?object $plan): void
    {
        if ($plan === null) {
            throw new DomainException(
                'Bonus refund plan not found'
            );
        }

        $alloc = $this->decodeAllocations(
            (string) $plan->allocations
        );

        $expiry = $this->decodeExpiry(
            (string) $plan->expiry_overrides
        );

        [$strict, $strictExpiry] = $this->normalize(
            (string) $plan->effect_kind,
            (int) $plan->source_id,
            array_column($alloc, 'units', 'grant_id'),
            $expiry
        );

        if (
            $strict !== $alloc
            || $strictExpiry !== $expiry
        ) {
            throw new RuntimeException(
                'Stored bonus plan is not canonical'
            );
        }

        $signed = $this->planData(
            (int) $plan->journal_id,
            (int) $plan->wallet_id,
            (string) $plan->effect_kind,
            (int) $plan->source_id,
            $alloc,
            $expiry,
            (string) $plan->calculation_sha256,
            (int) $plan->approved_by
        );

        if (!hash_equals(
            (string) $plan->plan_hmac,
            $this->signer->digest($signed)
        )) {
            throw new RuntimeException(
                'Bonus refund plan signature mismatch'
            );
        }
    }

    /** @return array<string,mixed> */
    private function presentPlan(object $plan): array
    {
        $this->verifyPlan($plan);

        return [
            'plan_id' => (int) $plan->id,
            'journal_id' => (int) $plan->journal_id,
            'effect_kind' => (string) $plan->effect_kind,
            'source_id' => (int) $plan->source_id,
            'wallet_id' => (int) $plan->wallet_id,
            'allocations' => $this->decodeAllocations(
                (string) $plan->allocations
            ),
            'status' => 'approved_not_posted',
        ];
    }

    /** @return array<string,mixed> */
    private function presentPosting(
        object $posting,
        object $plan
    ): array {
        $data = [
            'plan_id' => (int) $posting->plan_id,
            'loyalty_operation_id' =>
                (string) $posting->loyalty_operation_id,
            'posted_by' => (int) $posting->posted_by,
            'result_sha256' =>
                (string) $posting->result_sha256,
        ];

        if (!hash_equals(
            (string) $posting->posting_hmac,
            $this->signer->digest($data)
        )) {
            throw new RuntimeException(
                'Bonus refund posting signature mismatch'
            );
        }

        $operation = DB::table('loyalty_operations')
            ->where('id', $data['loyalty_operation_id'])
            ->where('wallet_id', $plan->wallet_id)
            ->where(
                'operation_key',
                $this->operationKey($plan)
            )
            ->first();

        if ($operation === null) {
            throw new RuntimeException(
                'Posted loyalty operation missing'
            );
        }

        $result = json_decode(
            (string) $operation->result,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        /*
         * BLOCK 57 FIX:
         * Verify a canonical JSON hash, independent of JSONB key order.
         */
        if (
            !is_array($result)
            || !hash_equals(
                (string) $posting->result_sha256,
                $this->resultHash($result)
            )
        ) {
            throw new RuntimeException(
                'Posted loyalty operation result changed'
            );
        }

        return [
            'plan_id' => (int) $plan->id,
            'journal_id' => (int) $plan->journal_id,
            'effect_kind' => (string) $plan->effect_kind,
            'status' => 'posted',
            'loyalty_operation_id' =>
                $data['loyalty_operation_id'],
            'result' => $result,
        ];
    }

    /**
     * Stable hash for a JSONB document.
     *
     * Object keys are sorted recursively.
     * Array/list order is preserved.
     * Scalar values and types remain unchanged.
     *
     * @param array<string|int,mixed> $result
     */
    private function resultHash(array $result): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalizeJson($result),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
        );
    }

    private function canonicalizeJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeJson($item);
        }

        return $value;
    }

    private function operationKey(object $plan): string
    {
        return sprintf(
            'mono57:j%d:%s:%d',
            (int) $plan->journal_id,
            (string) $plan->effect_kind,
            (int) $plan->source_id
        );
    }

    private function enabled(): void
    {
        if (
            config(
                'services.mono_acquiring.loyalty_refunds.posting_enabled'
            ) !== true
        ) {
            throw new DomainException(
                'Loyalty refund settlement is not enabled'
            );
        }
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            $user === null
            || !is_numeric($id)
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException(
                'Mono refund loyalty access denied'
            );
        }

        return (int) $id;
    }
}
