<?php

namespace Tests\Feature;

use App\Services\Loyalty\LoyaltyAdjustmentService;
use App\Services\Loyalty\LoyaltyRedemptionRefundService;
use App\Services\Loyalty\LoyaltyTransactionService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PostgreSQL integration checks for loyalty 8.3.5.1 (A–G).
 * Run against boardriders_test only; never production.
 */
final class LoyaltySystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pending_activation_and_repeated_command_are_idempotent(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $payload = $this->payload('purchase-101', 100);

        $first = $service->grantPending($wallet, 'grant-command-101', $payload);
        $repeat = $service->grantPending($wallet, 'grant-command-101', $payload);

        $this->assertEquals($first, $repeat);
        $this->assertDatabaseCount('loyalty_grants', 1);
        $this->assertSame(['pending' => 100, 'available' => 0, 'reserved' => 0, 'debt' => 0], $this->balance($wallet));

        $activated = $service->activateGrant($wallet, $first['grant_id'], 60, 'activation-101');
        $this->assertEquals($activated, $service->activateGrant($wallet, $first['grant_id'], 60, 'activation-101'));
        $this->assertSame(['pending' => 40, 'available' => 60, 'reserved' => 0, 'debt' => 0], $this->balance($wallet));
    }

    public function test_idempotency_key_cannot_be_reused_with_other_parameters(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $service->grantPending($wallet, 'same-command', $this->payload('grant-a', 30));

        $this->expectException(DomainException::class);
        $service->grantPending($wallet, 'same-command', $this->payload('grant-b', 31));
    }

    public function test_early_or_unapproved_activation_is_rejected(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $future = $this->payload('waiting', 10);
        $future['activation_not_before_at'] = CarbonImmutable::now('UTC')->addDay();
        $futureGrant = $service->grantPending($wallet, 'pending-future', $future)['grant_id'];

        try {
            $service->activateGrant($wallet, $futureGrant, 10, 'activate-too-early');
            $this->fail('Premature activation was allowed');
        } catch (DomainException) {
            $this->assertSame(10, $this->balance($wallet)['pending']);
        }

        $review = $this->payload('review', 20);
        $review['activation_requirements'] = ['moderation' => true];
        $reviewGrant = $service->grantPending($wallet, 'pending-review', $review)['grant_id'];

        $this->expectException(DomainException::class);
        $service->activateGrant($wallet, $reviewGrant, 20, 'activate-unapproved');
    }

    public function test_earliest_expiring_grants_are_reserved_first_and_captured(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $long = $this->issueAndActivate($wallet, 'long', 100, CarbonImmutable::now('UTC')->addDays(20));
        $short = $this->issueAndActivate($wallet, 'short', 60, CarbonImmutable::now('UTC')->addDays(2));
        $forever = $this->issueAndActivate($wallet, 'forever', 90);

        $reservation = $service->reserve($wallet, 'reserve-1', 'res-1', 'order-1', 130, CarbonImmutable::now('UTC')->addHour());
        $this->assertSame(130, $reservation['reserved_units']);
        $this->assertSame([
            ['grant_id' => $short, 'units' => 60, 'rule_version' => 'v1'],
            ['grant_id' => $long, 'units' => 70, 'rule_version' => 'v1'],
        ], $reservation['allocations']);

        $this->assertSame(['pending' => 0, 'available' => 120, 'reserved' => 130, 'debt' => 0], $this->balance($wallet));
        $captured = $service->captureReservation($wallet, $reservation['reservation_id'], 'capture-1', CarbonImmutable::now('UTC'));
        $this->assertSame(130, $captured['captured_units']);
        $this->assertSame(['pending' => 0, 'available' => 120, 'reserved' => 0, 'debt' => 0], $this->balance($wallet));

        $this->expectException(DomainException::class);
        $service->captureReservation($wallet, $reservation['reservation_id'], 'capture-again-different-key', CarbonImmutable::now('UTC'));
    }

    public function test_releasing_a_reservation_restores_available_units_once(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $this->issueAndActivate($wallet, 'release-funds', 80);
        $reserved = $service->reserve($wallet, 'reserve-release', 'reserve-release', 'order-release', 50, CarbonImmutable::now('UTC')->addHours(2));

        $released = $service->releaseReservation($wallet, $reserved['reservation_id'], 'release-command');
        $this->assertSame(50, $released['released_units']);
        $this->assertSame(0, $released['expired_units']);
        $this->assertSame(['pending' => 0, 'available' => 80, 'reserved' => 0, 'debt' => 0], $this->balance($wallet));
        $this->assertEquals($released, $service->releaseReservation($wallet, $reserved['reservation_id'], 'release-command'));

        $this->expectException(DomainException::class);
        $service->releaseReservation($wallet, $reserved['reservation_id'], 'release-other-key');
    }

    public function test_refund_of_captured_bonus_is_partial_and_cannot_exceed_original(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $refunds = app(LoyaltyRedemptionRefundService::class);
        $grant = $this->issueAndActivate($wallet, 'refund-grant', 150);
        $reservation = $service->reserve($wallet, 'reserve-refund', 'reserve-refund', 'order-refund', 100, CarbonImmutable::now('UTC')->addHour());
        $service->captureReservation($wallet, $reservation['reservation_id'], 'capture-refund', CarbonImmutable::now('UTC'));

        $first = $refunds->refundCapturedBonuses($wallet, $reservation['reservation_id'], 'refund-event-1', 'returned-line-1', [$grant => 40]);
        $this->assertSame(40, $first['refunded_units']);
        $this->assertEquals($first, $refunds->refundCapturedBonuses($wallet, $reservation['reservation_id'], 'refund-event-1', 'returned-line-1', [$grant => 40]));
        $this->assertSame(['pending' => 0, 'available' => 90, 'reserved' => 0, 'debt' => 0], $this->balance($wallet));

        try {
            $refunds->refundCapturedBonuses($wallet, $reservation['reservation_id'], 'refund-overflow', 'returned-line-overflow', [$grant => 61]);
            $this->fail('Over-refunding was allowed');
        } catch (DomainException) {
            $this->assertSame(1, DB::table('loyalty_redemption_refunds')->count());
        }

        $second = $refunds->refundCapturedBonuses($wallet, $reservation['reservation_id'], 'refund-event-2', 'returned-line-2', [$grant => 60]);
        $this->assertSame(60, $second['refunded_units']);
        $this->assertSame(150, $this->balance($wallet)['available']);
    }

    public function test_reversed_spent_grant_creates_bonus_debt_and_blocks_new_redemptions(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $adjustments = app(LoyaltyAdjustmentService::class);
        $purchase = $this->issueAndActivate($wallet, 'spent-purchase', 100);
        $reservation = $service->reserve($wallet, 'reserve-spent', 'reserve-spent', 'order-spent', 60, CarbonImmutable::now('UTC')->addHours(2));
        $service->captureReservation($wallet, $reservation['reservation_id'], 'capture-spent', CarbonImmutable::now('UTC'));

        $reversed = $adjustments->reverseGrant($wallet, $purchase, 80, 'reverse-spent', 'product_return', 'return-line-123');
        $this->assertSame(40, $reversed['available_removed']);
        $this->assertSame(40, $reversed['bonus_debt_created']);
        $this->assertSame(['pending' => 0, 'available' => 0, 'reserved' => 0, 'debt' => 40], $this->balance($wallet));

        $gift = $this->issueAndActivate($wallet, 'compensation-gift', 10, null, 'compensation');
        $this->assertGreaterThan(0, $gift);
        $this->assertSame(['pending' => 0, 'available' => 10, 'reserved' => 0, 'debt' => 40], $this->balance($wallet));
        $newPurchase = $this->issueAndActivate($wallet, 'new-purchase', 20);
        $this->assertGreaterThan(0, $newPurchase);
        $this->assertSame(['pending' => 0, 'available' => 10, 'reserved' => 0, 'debt' => 20], $this->balance($wallet));

        $this->expectException(DomainException::class);
        $service->reserve($wallet, 'blocked-while-debt', 'blocked-while-debt', 'blocked-order', 5, CarbonImmutable::now('UTC')->addHour());
    }

    public function test_grant_expiration_and_history_adjustment_do_not_recreate_spent_units(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $adjustments = app(LoyaltyAdjustmentService::class);
        $grant = $service->grantPending($wallet, 'grant-expiring', $this->payload('expiring', 50, 'purchase', CarbonImmutable::now('UTC')->addDay()))['grant_id'];

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));
        $expired = $service->expireGrant($wallet, $grant, 'expire-grant');
        $this->assertSame(50, $expired['expired_pending_units']);
        $this->assertSame(0, $this->balance($wallet)['pending']);

        $change = $adjustments->changeGrantExpiry($wallet, $grant, 'extend-grant', CarbonImmutable::now('UTC')->addDays(3), 'customer_support', 'v2');
        $this->assertNotNull($change['adjustment_id']);
        $this->assertEquals($change, $adjustments->changeGrantExpiry($wallet, $grant, 'extend-grant', CarbonImmutable::now('UTC')->addDays(3), 'customer_support', 'v2'));
        $this->assertSame(['pending' => 0, 'available' => 0, 'reserved' => 0, 'debt' => 0], $this->balance($wallet));
    }

    public function test_refund_after_expiration_can_restore_only_approved_units(): void
    {
        $wallet = $this->wallet();
        $service = app(LoyaltyTransactionService::class);
        $refunds = app(LoyaltyRedemptionRefundService::class);
        $grant = $this->issueAndActivate($wallet, 'old-bonus', 50, CarbonImmutable::now('UTC')->addDay());
        $reservation = $service->reserve($wallet, 'reserve-old', 'reserve-old', 'order-old', 30, CarbonImmutable::now('UTC')->addHour());
        $service->captureReservation($wallet, $reservation['reservation_id'], 'capture-old', CarbonImmutable::now('UTC'));

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));
        $newExpiry = CarbonImmutable::now('UTC')->addDays(5);
        $restored = $refunds->refundCapturedBonuses($wallet, $reservation['reservation_id'], 'refund-old', 'return-old', [$grant => 10], [$grant => $newExpiry]);

        $this->assertSame(10, $restored['refunded_units']);
        $this->assertSame(['pending' => 0, 'available' => 10, 'reserved' => 0, 'debt' => 0], $this->balance($wallet));
        $this->assertSame(1, DB::table('loyalty_grant_expiry_adjustments')->where('grant_id', $grant)->count());
        $this->assertSame(1, DB::table('loyalty_ledger_entries')->where('grant_id', $grant)->where('entry_type', 'grant_expiration')->count());
    }

    public function test_wallets_from_different_markets_cannot_use_each_others_grants(): void
    {
        $account = $this->guestAccount('shared-customer');
        $uaWallet = $this->walletForAccount($account, 'UA');
        $otherWallet = $this->walletForAccount($account, 'UA_B2B');
        $service = app(LoyaltyTransactionService::class);
        $grant = $service->grantPending($uaWallet, 'ua-grant-op', $this->payload('ua-only-grant', 25))['grant_id'];

        $this->assertSame(25, $this->balance($uaWallet)['pending']);
        $this->assertSame(0, $this->balance($otherWallet)['pending']);

        $this->expectException(DomainException::class);
        $service->activateGrant($otherWallet, $grant, 25, 'cross-market-activation');
    }

    public function test_ledger_rows_are_immutable_on_postgresql(): void
    {
        $wallet = $this->wallet();
        app(LoyaltyTransactionService::class)->grantPending($wallet, 'immutable-op', $this->payload('immutable-grant', 5));

        $this->expectException(QueryException::class);
        DB::table('loyalty_ledger_entries')->where('wallet_id', $wallet)->update(['pending_delta' => 999]);
    }

    public function test_ledger_rows_cannot_be_deleted(): void
    {
        $wallet = $this->wallet();
        app(LoyaltyTransactionService::class)->grantPending($wallet, 'immutable-delete-op', $this->payload('immutable-delete-grant', 5));

        $this->expectException(QueryException::class);
        DB::table('loyalty_ledger_entries')->where('wallet_id', $wallet)->delete();
    }

    public function test_grants_are_immutable_on_postgresql(): void
    {
        $wallet = $this->wallet();
        $grant = app(LoyaltyTransactionService::class)->grantPending($wallet, 'grant-immutability', $this->payload('grant-immutability-key', 5))['grant_id'];

        $this->expectException(QueryException::class);
        DB::table('loyalty_grants')->where('id', $grant)->update(['units_total' => 555]);
    }

    public function test_database_rejects_zero_ledger_entries(): void
    {
        $wallet = $this->wallet();
        $grant = app(LoyaltyTransactionService::class)->grantPending($wallet, 'seed-zero', $this->payload('zero-check', 20));

        $this->expectException(QueryException::class);
        DB::table('loyalty_ledger_entries')->insert([
            'wallet_id' => $wallet,
            'market_id' => DB::table('loyalty_wallets')->where('id', $wallet)->value('market_id'),
            'grant_id' => $grant['grant_id'],
            'operation_id' => $grant['operation_id'],
            'entry_key' => 'invalid-zero',
            'entry_type' => 'invalid_zero',
            'origin' => 'test',
            'occurred_at' => now(),
        ]);
    }

    public function test_database_rejects_grant_from_other_market(): void
    {
        $account = $this->guestAccount('market-constraint');
        $uaWallet = $this->walletForAccount($account, 'UA');
        $otherWallet = $this->walletForAccount($account, 'UA_B2B');
        $service = app(LoyaltyTransactionService::class);
        $first = $service->grantPending($uaWallet, 'seed-ua', $this->payload('for-ua', 10));
        $second = $service->grantPending($otherWallet, 'seed-other', $this->payload('for-other', 20));

        $this->expectException(QueryException::class);
        DB::table('loyalty_ledger_entries')->insert([
            'wallet_id' => $uaWallet,
            'market_id' => DB::table('loyalty_wallets')->where('id', $uaWallet)->value('market_id'),
            'grant_id' => $second['grant_id'],
            'operation_id' => $first['operation_id'],
            'entry_key' => 'invalid-cross-market',
            'entry_type' => 'invalid_cross_market',
            'pending_delta' => 1,
            'origin' => 'test',
            'occurred_at' => now(),
        ]);
    }

    public function test_ledger_cannot_be_truncated(): void
    {
        $wallet = $this->wallet();
        app(LoyaltyTransactionService::class)->grantPending($wallet, 'immutable-truncate-op', $this->payload('immutable-truncate-grant', 5));

        $this->expectException(QueryException::class);
        DB::statement('TRUNCATE TABLE loyalty_ledger_entries');
    }

    private function payload(string $key, int $units, string $type = 'purchase', ?CarbonImmutable $expiry = null): array
    {
        return [
            'grant_key' => $key,
            'grant_type' => $type,
            'source_type' => 'test_order',
            'source_ref' => $key,
            'program_code' => 'TEST_PROGRAM',
            'rule_version' => 'v1',
            'units_total' => $units,
            'activation_not_before_at' => null,
            'expires_at' => $expiry,
            'activation_requirements' => [],
            'rule_snapshot' => ['repay_bonus_debt' => $type === 'purchase'],
            'context' => ['test' => true],
        ];
    }

    private function issueAndActivate(
        int $wallet,
        string $key,
        int $units,
        ?CarbonImmutable $expiry = null,
        string $type = 'purchase'
    ): int {
        $service = app(LoyaltyTransactionService::class);
        $grantId = (int) $service->grantPending($wallet, 'grant-'.$key, $this->payload($key, $units, $type, $expiry))['grant_id'];
        $service->activateGrant($wallet, $grantId, $units, 'activate-'.$key);
        return $grantId;
    }

    private function wallet(): int
    {
        return $this->walletForAccount($this->guestAccount('guest-'.Str::uuid()), 'UA');
    }

    private function guestAccount(string $identity): int
    {
        return (int) DB::table('loyalty_accounts')->insertGetId([
            'identity_state' => 'guest',
            'guest_subject_hash' => hash_hmac('sha256', $identity, 'test-only-hmac-key'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function walletForAccount(int $accountId, string $code): int
    {
        $marketId = DB::table('markets')->where('code', $code)->value('id');
        if ($marketId === null) {
            $marketId = DB::table('markets')->insertGetId([
                'code' => $code,
                'name' => 'Test market '.$code,
                'country_code' => 'UA',
                'currency_code' => 'UAH',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) DB::table('loyalty_wallets')->insertGetId([
            'loyalty_account_id' => $accountId,
            'market_id' => $marketId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{pending:int,available:int,reserved:int,debt:int} */
    private function balance(int $walletId): array
    {
        $row = DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $walletId)
            ->selectRaw('COALESCE(SUM(pending_delta),0)::bigint AS pending')
            ->selectRaw('COALESCE(SUM(available_delta),0)::bigint AS available')
            ->selectRaw('COALESCE(SUM(reserved_delta),0)::bigint AS reserved')
            ->selectRaw('COALESCE(SUM(debt_delta),0)::bigint AS debt')
            ->first();

        return [
            'pending' => (int) $row->pending,
            'available' => (int) $row->available,
            'reserved' => (int) $row->reserved,
            'debt' => (int) $row->debt,
        ];
    }
}
