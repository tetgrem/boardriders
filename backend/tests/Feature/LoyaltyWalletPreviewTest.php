<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Loyalty\LoyaltyAdjustmentService;
use App\Services\Loyalty\LoyaltyTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Лише PostgreSQL test DB (boardriders_test), не основна база!
 * Перевіряємо доступ та відсутність side effects у preview.
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

        // Два різні ринки можуть використовувати одну валюту,
        // але бонуси між ними не переміщуються.
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

    public function test_user_without_verified_account_has_no_wallet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'no_verified_wallet')
            ->assertJsonPath('data.informational_only', true);
    }

    public function test_expired_units_are_excluded_without_writing_to_ledger(): void
    {
        $user = User::factory()->create();
        $walletId = $this->wallet($user, 'UA');
        $service = app(LoyaltyTransactionService::class);

        $this->issue(
            $service,
            $walletId,
            'short',
            30,
            CarbonImmutable::now('UTC')->addDay()
        );
        $this->issue(
            $service,
            $walletId,
            'long',
            70,
            CarbonImmutable::now('UTC')->addDays(20)
        );

        // Reserve() витрачає партії за найближчим строком.
        // Після резервування 20 бонусів: 10 short + 70 long.
        $service->reserve(
            $walletId,
            'preview-reserve-key',
            'preview-reservation',
            'preview-order',
            20,
            CarbonImmutable::now('UTC')->addDays(3)
        );

        $before = DB::table('loyalty_ledger_entries')->count();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.pending_units', 0)
            ->assertJsonPath('data.available_units', 80)
            ->assertJsonPath('data.reserved_units', 20)
            ->assertJsonPath('data.spendable_units', 70)
            ->assertJsonPath('data.blocked_reason', null);

        $this->assertSame(
            $before,
            DB::table('loyalty_ledger_entries')->count(),
            'Preview must never write to loyalty ledger'
        );
    }

    public function test_bonus_debt_blocks_new_redemptions(): void
    {
        $user = User::factory()->create();
        $walletId = $this->wallet($user, 'UA');
        $service = app(LoyaltyTransactionService::class);
        $grantId = $this->issue($service, $walletId, 'purchase', 80);

        $reservation = $service->reserve(
            $walletId,
            'debt-reserve-command',
            'debt-reservation',
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

        // Після використання 60 та повернення 50 бонусів
        // виникає внутрішній борг на 30 одиниць.
        app(LoyaltyAdjustmentService::class)->reverseGrant(
            $walletId,
            $grantId,
            50,
            'debt-reversal',
            'product_return',
            'return-line-1'
        );

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.debt_units', 30)
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'bonus_debt');
    }

    public function test_different_market_and_different_user_are_isolated(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $walletId = $this->wallet($owner, 'UA');

        $this->issue(
            app(LoyaltyTransactionService::class),
            $walletId,
            'only-UA',
            50
        );

        $this->actingAs($owner)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 50);

        $this->getJson('/api/v1/loyalty/wallet/preview?market_code=UA_SECOND')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0);

        $this->actingAs($other)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'no_verified_wallet');
    }

    public function test_frozen_wallet_and_inactive_market_cannot_be_used(): void
    {
        $user = User::factory()->create();
        $walletId = $this->wallet($user, 'UA');
        $this->issue(
            app(LoyaltyTransactionService::class),
            $walletId,
            'frozen',
            50
        );

        DB::table('loyalty_wallets')
            ->where('id', $walletId)
            ->update(['status' => 'frozen']);

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.spendable_units', 0)
            ->assertJsonPath('data.blocked_reason', 'wallet_not_active');

        DB::table('markets')
            ->where('code', 'UA')
            ->update(['is_active' => false]);

        $this->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertNotFound();
    }

    public function test_last_expiry_adjustment_can_make_grant_unlimited(): void
    {
        $user = User::factory()->create();
        $walletId = $this->wallet($user, 'UA');
        $service = app(LoyaltyTransactionService::class);
        $grantId = $this->issue(
            $service,
            $walletId,
            'extended',
            40,
            CarbonImmutable::now('UTC')->addDay()
        );

        app(LoyaltyAdjustmentService::class)->changeGrantExpiry(
            $walletId,
            $grantId,
            'extend-to-unlimited',
            null,
            'customer_support',
            'v2'
        );

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(5));

        $this->actingAs($user)
            ->getJson('/api/v1/loyalty/wallet/preview?market_code=UA')
            ->assertOk()
            ->assertJsonPath('data.available_units', 40)
            ->assertJsonPath('data.spendable_units', 40)
            ->assertJsonPath('data.blocked_reason', null);
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
