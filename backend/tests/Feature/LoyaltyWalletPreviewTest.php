<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Loyalty\LoyaltyTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Бонусний preview лише читає стан; checkout резервуватиме
 * кошти окремо, після повторної перевірки правил та суми.
 */
final class LoyaltyWalletPreviewTest extends TestCase
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

        foreach (['UA', 'UA_SECOND'] as $code) {
            DB::table('markets')->insert([
                'code' => $code,
                'name' => $code,
                'country_code' => 'UA',
                'currency_code' => 'UAH',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_customer_cannot_read_wallet(): void
    {
        $this->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertUnauthorized();
    }

    public function test_wallet_preview_is_zero_for_user_without_verified_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'no_verified_wallet');
    }

    public function test_active_verified_wallet_shows_only_unexpired_unreserved_grants(): void
    {
        $user = User::factory()->create();
        $walletId = $this->wallet($user, 'UA');
        $service = app(LoyaltyTransactionService::class);

        $this->issue($service, $walletId, 'expiring', 30, CarbonImmutable::now('UTC')->addDay());
        $this->issue($service, $walletId, 'lasting', 70, CarbonImmutable::now('UTC')->addDays(20));

        $service->reserve(
            $walletId,
            'preview-reserve',
            'preview-reserve',
            'preview-order',
            20,
            CarbonImmutable::now('UTC')->addDays(3)
        );

        $beforeCount = DB::table('loyalty_ledger_entries')->count();

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));

        $response = $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.pending_units', 0)
            ->assertJsonPath('data.available_units', 80)
            ->assertJsonPath('data.reserved_units', 20)
            ->assertJsonPath('data.spendable_units', 70)
            ->assertJsonPath('data.blocked_reason', null)
            ->assertJsonPath('data.informational_only', true);

        $this->assertSame(
            $beforeCount,
            DB::table('loyalty_ledger_entries')->count(),
            'Preview must not write to ledger'
        );
    }

    public function test_debt_blocks_spendable_units_even_with_available_grants(): void
    {
        $user = User::factory()->create();
        $walletId = $this->wallet($user, 'UA');
        $service = app(LoyaltyTransactionService::class);
        $grantId = $this->issue($service, $walletId, 'purchase', 80);

        $reservation = $service->reserve(
            $walletId,
            'debt-reserve',
            'debt-reserve',
            'debt-order',
            60,
            CarbonImmutable::now('UTC')->addHour()
        );
        $service->captureReservation(
            $walletId,
            $reservation['reservation_id'],
            'debt-capture',
            CarbonImmutable::now('UTC')
        );

        app(\App\Services\Loyalty\LoyaltyAdjustmentService::class)
            ->reverseGrant(
                $walletId,
                $grantId,
                50,
                'preview-reversal',
                'product_return',
                'preview-return-1'
            );

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.debt_units', 30)
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'bonus_debt');
    }

    public function test_separate_markets_and_users_never_share_balance(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walletId = $this->wallet($owner, 'UA');
        $this->issue(app(LoyaltyTransactionService::class), $walletId, 'market-only', 50);

        $this->actingAs($owner)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA_SECOND')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0);

        $this->actingAs($other)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'no_verified_wallet');
    }

    public function test_frozen_wallet_and_unknown_market_cannot_be_spent(): void
    {
        $user = User::factory()->create();
        $walletId = $this->wallet($user, 'UA');
        $this->issue(app(LoyaltyTransactionService::class), $walletId, 'frozen', 50);

        DB::table('loyalty_wallets')
            ->where('id', $walletId)
            ->update(['status' => 'frozen']);

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'wallet_not_active');

        $this->getJson('/api/v1/loyalty/wallet/preview?market_code=UNKNOWN')
            ->assertNotFound();
    }

    private function wallet(User $user, string $marketCode): int
    {
        $accountId = (int) DB::table('loyalty_accounts')->insertGetId([
            'user_id' => $user->id,
            'identity_state' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('loyalty_wallets')->insertGetId([
            'loyalty_account_id' => $accountId,
            'market_id' => DB::table('markets')
                ->where('code', $marketCode)
                ->value('id'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issue(
        LoyaltyTransactionService $service,
        int $walletId,
        string $key,
        int $units,
        ?CarbonImmutable $expiry = null
    ): int {
        $grant = $service->grantPending($walletId, 'grant-'.$key, [
            'grant_key' => $key.'-'.Str::uuid(),
            'grant_type' => 'purchase',
            'source_type' => 'test_order',
            'source_ref' => $key,
            'program_code' => 'TEST',
            'rule_version' => 'v1',
            'units_total' => $units,
            'expires_at' => $expiry,
            'activation_requirements' => [],
            'rule_snapshot' => ['repay_bonus_debt' => true],
        ]);

        $service->activateGrant(
            $walletId,
            (int) $grant['grant_id'],
            $units,
            'activate-'.$key
        );

        return (int) $grant['grant_id'];
    }
}
