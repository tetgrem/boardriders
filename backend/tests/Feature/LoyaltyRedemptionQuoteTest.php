<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Loyalty\LoyaltyRedemptionQuoteService;
use App\Services\Loyalty\LoyaltyTransactionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Read-only перевірки внутрішнього quote-сервісу.
 * Використовувати виключно PostgreSQL boardriders_test.
 */
final class LoyaltyRedemptionQuoteTest extends TestCase
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

    public function test_no_configured_policy_never_assumes_an_exchange_rate(): void
    {
        $user = User::factory()->create();

        $result = $this->quote($user, 1000, 0);

        $this->assertSame(
            'no_active_policy',
            $result['reason']
        );

        $this->assertSame(
            0,
            $result['max_discount_minor']
        );

        $this->assertNull($result['policy']);
    }

    public function test_integer_steps_respect_share_cash_floor_and_fixed_cap(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 'UA', 500);
        $this->policy('UA');

        $before = DB::table(
            'loyalty_ledger_entries'
        )->count();

        $result = $this->quote(
            $user,
            1000,
            120
        );

        // Умови тесту:
        // 1000 * 25% = 250
        // max_discount = 200
        // minimum_cash = 850 => максимум знижки 150
        // 3 бонуси = 2 копійки
        // 75 кроків = 225 бонусів / 150 копійок

        $this->assertSame(
            225,
            $result['max_redeemable_units']
        );

        $this->assertSame(
            150,
            $result['max_discount_minor']
        );

        $this->assertSame(
            120,
            $result['applied_units']
        );

        $this->assertSame(
            80,
            $result['discount_minor']
        );

        $this->assertSame(
            920,
            $result['goods_to_pay_minor']
        );

        $this->assertSame(
            'v1',
            $result['policy']['rule_version']
        );

        $this->assertTrue(
            $result['informational_only']
        );

        $this->assertSame(
            $before,
            DB::table('loyalty_ledger_entries')->count()
        );
    }

    public function test_requested_units_must_match_the_exchange_step(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 'UA', 500);
        $this->policy('UA');

        $result = $this->quote(
            $user,
            1000,
            5
        );

        $this->assertSame(
            'invalid_bonus_step',
            $result['reason']
        );

        $this->assertSame(
            0,
            $result['applied_units']
        );

        $this->assertSame(
            0,
            $result['discount_minor']
        );
    }

    public function test_request_exceeding_limit_is_not_silently_accepted(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 'UA', 500);
        $this->policy('UA');

        $result = $this->quote(
            $user,
            1000,
            300
        );

        $this->assertSame(
            'requested_units_exceed_limit',
            $result['reason']
        );

        $this->assertSame(
            225,
            $result['max_redeemable_units']
        );

        $this->assertSame(
            0,
            $result['applied_units']
        );
    }

    public function test_new_disabled_version_overrides_previous_active_version(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 'UA', 500);

        $this->policy('UA');

        // Нову версію додаємо, стару не змінюємо.
        $this->policy(
            'UA',
            'v2',
            false,
            now()->subMinute()
        );

        $result = $this->quote(
            $user,
            1000,
            120
        );

        $this->assertSame(
            'policy_disabled',
            $result['reason']
        );

        $this->assertSame(
            'v2',
            $result['policy']['rule_version']
        );

        $this->assertSame(
            0,
            $result['max_redeemable_units']
        );
    }

    public function test_market_balances_are_never_interchangeable(): void
    {
        $user = User::factory()->create();

        // Бонуси нараховано лише в UA.
        $this->fund($user, 'UA', 500);

        // В іншому ринку є власна політика.
        $this->policy('UA_SECOND');

        $result = app(
            LoyaltyRedemptionQuoteService::class
        )->quoteForVerifiedUser(
            $user->id,
            'UA_SECOND',
            'UAH',
            1000,
            120
        );

        $this->assertSame(
            'no_verified_wallet',
            $result['reason']
        );

        $this->assertSame(
            0,
            $result['spendable_wallet_units']
        );

        $this->assertSame(
            0,
            $result['discount_minor']
        );
    }

    public function test_market_and_cart_currency_mismatch_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(
            DomainException::class
        );

        app(LoyaltyRedemptionQuoteService::class)
            ->quoteForVerifiedUser(
                $user->id,
                'UA',
                'USD',
                1000
            );
    }

    public function test_policy_history_is_immutable(): void
    {
        $id = $this->policy('UA');

        $this->expectException(
            QueryException::class
        );

        DB::table('loyalty_redemption_policies')
            ->where('id', $id)
            ->update([
                'max_goods_share_bps' => 10000,
            ]);
    }

    private function quote(
        User $user,
        int $minor,
        ?int $units
    ): array {
        return app(
            LoyaltyRedemptionQuoteService::class
        )->quoteForVerifiedUser(
            $user->id,
            'UA',
            'UAH',
            $minor,
            $units
        );
    }

    private function policy(
        string $marketCode,
        string $version = 'v1',
        bool $enabled = true,
        ?\DateTimeInterface $from = null
    ): int {
        return (int) DB::table(
            'loyalty_redemption_policies'
        )->insertGetId([
            'market_id' => DB::table('markets')
                ->where('code', $marketCode)
                ->value('id'),

            'currency_code' => 'UAH',
            'program_code' => 'TEST_PROGRAM',
            'rule_version' => $version,

            'is_enabled' => $enabled,

            // Лише тестові значення.
            'bonus_units_per_step' => 3,
            'money_minor_per_step' => 2,

            'max_goods_share_bps' => 2500,
            'max_discount_minor' => 200,
            'minimum_cash_minor' => 850,

            'effective_from' =>
                $from ?? now()->subDay(),
        ]);
    }

    private function fund(
        User $user,
        string $marketCode,
        int $units
    ): void {
        $accountId = (int) DB::table(
            'loyalty_accounts'
        )->insertGetId([
            'user_id' => $user->id,
            'identity_state' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $walletId = (int) DB::table(
            'loyalty_wallets'
        )->insertGetId([
            'loyalty_account_id' => $accountId,

            'market_id' => DB::table('markets')
                ->where('code', $marketCode)
                ->value('id'),

            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(
            LoyaltyTransactionService::class
        );

        $grant = $service->grantPending(
            $walletId,
            'quote-grant',
            [
                'grant_key' => 'quote-grant',
                'grant_type' => 'purchase',
                'source_type' => 'test_order',
                'source_ref' => 'test-quote-order',

                'program_code' => 'TEST_PROGRAM',
                'rule_version' => 'v1',

                'units_total' => $units,
                'activation_requirements' => [],

                'rule_snapshot' => [
                    'repay_bonus_debt' => true,
                ],
            ]
        );

        $service->activateGrant(
            $walletId,
            (int) $grant['grant_id'],
            $units,
            'quote-activation'
        );
    }
}
