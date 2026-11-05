<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoyaltyMarketFoundationTest extends TestCase
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

    public function test_one_account_has_distinct_wallets_for_markets_with_same_currency(): void
    {
        $accountId = $this->guestAccount('guest-one');
        $ukraine = $this->market('UA', 'UA');
        $otherMarket = $this->market('UA_B2B', 'UA');

        $ukraineWallet = $this->wallet($accountId, $ukraine);
        $otherWallet = $this->wallet($accountId, $otherMarket);

        $this->assertNotSame($ukraineWallet, $otherWallet);
        $this->assertDatabaseCount('loyalty_wallets', 2);

        $this->assertDatabaseHas('loyalty_wallets', [
            'id' => $ukraineWallet,
            'loyalty_account_id' => $accountId,
            'market_id' => $ukraine,
        ]);

        $this->assertDatabaseHas('loyalty_wallets', [
            'id' => $otherWallet,
            'loyalty_account_id' => $accountId,
            'market_id' => $otherMarket,
        ]);
    }

    public function test_duplicate_wallet_for_account_and_market_is_rejected(): void
    {
        $accountId = $this->guestAccount('guest-two');
        $marketId = $this->market('UA', 'UA');

        $this->wallet($accountId, $marketId);

        $this->expectException(QueryException::class);
        $this->wallet($accountId, $marketId);
    }

    public function test_guest_account_requires_a_subject_hash(): void
    {
        $this->expectException(QueryException::class);

        DB::table('loyalty_accounts')->insert([
            'identity_state' => 'guest',
            'user_id' => null,
            'guest_subject_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_guest_identity_cannot_be_registered_twice(): void
    {
        $this->guestAccount('same-guest');

        $this->expectException(QueryException::class);
        $this->guestAccount('same-guest');
    }

    public function test_verified_state_requires_a_linked_user(): void
    {
        $this->expectException(QueryException::class);

        DB::table('loyalty_accounts')->insert([
            'identity_state' => 'verified',
            'user_id' => null,
            'guest_subject_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_market_cannot_be_removed_while_wallets_reference_it(): void
    {
        $accountId = $this->guestAccount('guest-three');
        $marketId = $this->market('UA', 'UA');

        $this->wallet($accountId, $marketId);

        $this->expectException(QueryException::class);

        DB::table('markets')
            ->where('id', $marketId)
            ->delete();
    }

    private function market(
        string $code,
        string $countryCode
    ): int {
        return (int) DB::table('markets')->insertGetId([
            'code' => $code,
            'name' => 'Test market '.$code,
            'country_code' => $countryCode,
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function guestAccount(string $subject): int
    {
        return (int) DB::table('loyalty_accounts')->insertGetId([
            'identity_state' => 'guest',
            // Лише тестовий ключ, не для production.
            'guest_subject_hash' => hash_hmac(
                'sha256',
                $subject,
                'test-only-key'
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function wallet(int $accountId, int $marketId): int
    {
        return (int) DB::table('loyalty_wallets')->insertGetId([
            'loyalty_account_id' => $accountId,
            'market_id' => $marketId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
