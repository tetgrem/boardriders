<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Pricing\CheckoutMonoRefund56JournalService;
use App\Services\Pricing\CheckoutMonoRefund57LoyaltyService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Extends the existing paid Mono → confirmed RMA → journal fixture. */
trait CheckoutMonoRefund57Cases
{
    /** @return array{journal_id:int,order_key:string} */
    private function confirmedJournal57(): array
    {
        $fixture = $this->providerSuccess56();
        $this->actor(5601);
        $journal = app(CheckoutMonoRefund56JournalService::class)
            ->record($fixture['execution_id']);
        return ['journal_id' => $journal['journal_id'], 'order_key' => $fixture['order_key']];
    }

    private function purchaseGrant57(string $orderKey, int $units = 40, ?string $grantSourceRef = null): array
    {
        $orderId = DB::table('orders')->where('order_key', $orderKey)->value('id');
        $marketId = (int) DB::table('checkout_order_preparations')
            ->where('order_id', $orderId)->value('market_id');
        $accountId = (int) DB::table('loyalty_accounts')->insertGetId([
            'identity_state' => 'guest',
            'guest_subject_hash' => hash('sha256', 'mono57-guest-' . Str::uuid()),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $walletId = (int) DB::table('loyalty_wallets')->insertGetId([
            'loyalty_account_id' => $accountId,
            'market_id' => $marketId,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $grant = app(LoyaltyTransactionService::class)->grantPending(
            $walletId,
            'mono57-seed-' . Str::uuid(),
            [
                'grant_key' => 'mono57-grant-' . Str::uuid(),
                'grant_type' => 'purchase',
                'source_type' => 'order',
                'source_ref' => $grantSourceRef ?? $orderKey,
                'program_code' => 'MONO57_TEST',
                'rule_version' => 'v1',
                'units_total' => $units,
                'activation_not_before_at' => null,
                'expires_at' => null,
                'activation_requirements' => [],
                'rule_snapshot' => ['repay_bonus_debt' => true],
                'context' => ['test' => true],
            ]
        );
        return ['wallet_id' => $walletId, 'grant_id' => (int) $grant['grant_id']];
    }

    private function approvedEarned57(array $j, int $units = 20): array
    {
        $source = $this->purchaseGrant57($j['order_key']);
        $this->actor(5701);
        $plan = app(CheckoutMonoRefund57LoyaltyService::class)->approve(
            $j['journal_id'], 'reverse_earned', $source['wallet_id'],
            $source['grant_id'], [$source['grant_id'] => $units],
            hash('sha256', 'reviewed-oms-return-allocation-57')
        );
        return [...$source, ...$plan];
    }

    public function test_cod_mono57_purchase_grant_reverses_once_through_existing_ledger(): void
    {
        $journal = $this->confirmedJournal57();
        $approved = $this->approvedEarned57($journal);
        $this->actor(5702);
        $service = app(CheckoutMonoRefund57LoyaltyService::class);
        $result = $service->post($approved['plan_id']);

        $this->assertSame('posted', $result['status']);
        $this->assertSame(20, $result['result']['reversed_units']);
        $this->assertDatabaseCount('checkout_mono_refund_loyalty_postings57', 1);
        $this->assertDatabaseCount('loyalty_grant_reversals', 1);
        $remaining = (int) DB::table('loyalty_ledger_entries')
            ->where('grant_id', $approved['grant_id'])->sum('pending_delta');
        $this->assertSame(20, $remaining);

        $replayed = $service->post($approved['plan_id']);
        $this->assertSame($result, $replayed);
        $this->assertDatabaseCount('checkout_mono_refund_loyalty_postings57', 1);
        $this->assertDatabaseCount('loyalty_grant_reversals', 1);
    }

    public function test_cod_mono57_same_allocation_approval_is_idempotent(): void
    {
        $journal = $this->confirmedJournal57();
        $approved = $this->approvedEarned57($journal);
        $this->actor(5701);
        $second = app(CheckoutMonoRefund57LoyaltyService::class)->approve(
            $journal['journal_id'], 'reverse_earned', $approved['wallet_id'],
            $approved['grant_id'], [$approved['grant_id'] => 20],
            hash('sha256', 'reviewed-oms-return-allocation-57')
        );
        $this->assertSame($approved['plan_id'], $second['plan_id']);
        $this->assertDatabaseCount('checkout_mono_refund_loyalty_plans57', 1);
    }

    public function test_cod_mono57_grant_from_another_order_is_rejected(): void
    {
        $journal = $this->confirmedJournal57();
        $source = $this->purchaseGrant57($journal['order_key'], 40, (string) Str::uuid());
        $this->actor(5701);
        $this->expectException(DomainException::class);
        app(CheckoutMonoRefund57LoyaltyService::class)->approve(
            $journal['journal_id'], 'reverse_earned', $source['wallet_id'],
            $source['grant_id'], [$source['grant_id'] => 10],
            hash('sha256', 'mismatched-order')
        );
    }

    public function test_cod_mono57_post_requires_independent_actor(): void
    {
        $journal = $this->confirmedJournal57();
        $approved = $this->approvedEarned57($journal);
        $this->actor(5701);
        $this->expectException(AuthorizationException::class);
        app(CheckoutMonoRefund57LoyaltyService::class)->post($approved['plan_id']);
    }

    public function test_cod_mono57_approval_requires_separate_ability(): void
    {
        $journal = $this->confirmedJournal57();
        $source = $this->purchaseGrant57($journal['order_key']);
        $this->actor(5601);
        $this->expectException(AuthorizationException::class);
        app(CheckoutMonoRefund57LoyaltyService::class)->approve(
            $journal['journal_id'], 'reverse_earned', $source['wallet_id'],
            $source['grant_id'], [$source['grant_id'] => 10], hash('sha256', 'attempt')
        );
    }

    public function test_cod_mono57_spent_bonus_requires_actual_checkout_reservation(): void
    {
        $journal = $this->confirmedJournal57();
        $source = $this->purchaseGrant57($journal['order_key']);
        $this->actor(5701);
        $this->expectException(DomainException::class);
        app(CheckoutMonoRefund57LoyaltyService::class)->approve(
            $journal['journal_id'], 'restore_spent', $source['wallet_id'],
            1234567, [$source['grant_id'] => 10], hash('sha256', 'missing-reservation')
        );
    }

    public function test_cod_mono57_posting_and_approval_are_database_immutable(): void
    {
        $journal = $this->confirmedJournal57();
        $approved = $this->approvedEarned57($journal);
        $this->expectException(QueryException::class);
        DB::table('checkout_mono_refund_loyalty_plans57')
            ->where('id', $approved['plan_id'])
            ->update(['approved_by' => 999]);
    }

    public function test_cod_mono57_posting_never_calls_mono(): void
    {
        $journal = $this->confirmedJournal57();
        $approved = $this->approvedEarned57($journal);
        $this->replaceMonoHttpFake([]);
        $this->actor(5702);
        app(CheckoutMonoRefund57LoyaltyService::class)->post($approved['plan_id']);
        Http::assertNothingSent();
    }
}
