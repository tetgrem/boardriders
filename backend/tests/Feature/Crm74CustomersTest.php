<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PricingFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Crm74CustomersTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/admin/crm74';

    private int $marketId;
    private int $priceListId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new \RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

        config()->set('crm64.enabled', true);
        config()->set('crm65.enabled', true);
        config()->set('crm74.enabled', true);

        $this->seed(PricingFoundationSeeder::class);

        DB::table('markets')->insertOrIgnore([
            'code' => 'UA',
            'name' => 'Ukraine',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->marketId = (int) DB::table('markets')
            ->where('code', 'UA')
            ->value('id');

        $this->priceListId = (int) DB::table('price_lists')
            ->where('code', 'UA_RETAIL')
            ->value('id');
    }

    private function staff(string $role = 'owner'): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Auth::forgetGuards();

        $this->withToken(
            $user->createToken(
                'crm65-dashboard',
                ['crm65:access']
            )->plainTextToken
        );

        return $user;
    }

    private function customer(array $data = []): User
    {
        return User::factory()->create($data);
    }

    private function order(
        ?User $customer,
        string $status = 'paid',
        int $amount = 400000,
        int $ageDays = 0
    ): int {
        $created = now()->subDays($ageDays);

        // Isolated read-model fixture. No actual payment is executed.
        $orderId = (int) DB::table('orders')
            ->insertGetId([
                'order_key' => (string) Str::uuid(),
                'payment_status' => $status,
                'created_at' => $created,
                'updated_at' => $created,
            ]);

        $json = json_encode([
            'schema_version' => 1,
            'currency_code' => 'UAH',
            'goods_payable_minor' => $amount,
            'lines' => [],
        ], JSON_THROW_ON_ERROR);

        $snapshotId = (int) DB::table(
            'order_financial_snapshots'
        )->insertGetId([
            'order_id' => $orderId,
            'schema_version' => 1,
            'currency_code' => 'UAH',
            'goods_payable_minor' => $amount,
            'snapshot_json' => $json,
            'snapshot_sha256' => hash('sha256', $json),
            'created_at' => $created,
        ]);

        DB::table('checkout_order_preparations')->insert([
            'order_id' => $orderId,
            'financial_snapshot_id' => $snapshotId,
            'market_id' => $this->marketId,
            'price_list_id' => $this->priceListId,
            'user_id' => $customer?->id,
            'bonus_units_reserved' => 0,
            'request_sha256' => hash('sha256', (string) Str::uuid()),
            'expires_at' => $created->copy()->addMinutes(30),
            'created_at' => $created,
        ]);

        return $orderId;
    }

    private function contact(array $overrides = []): array
    {
        return [
            'operation_key' => (string) Str::uuid(),
            'kind' => 'contact',
            'expected_contact_id' => null,
            'phone' => '+380501234567',
            'city' => 'Черкаси',
            'address' => 'Тестова адреса 10',
            'preferred_channel' => 'telegram',
            ...$overrides,
        ];
    }

    private function write(User $customer, array $body)
    {
        return $this->postJson(
            self::API . "/customers/{$customer->id}/write",
            $body
        );
    }

    private function profile(User $customer)
    {
        return $this->getJson(
            self::API . "/customers/{$customer->id}"
        );
    }

    private function wallet(User $customer): int
    {
        $account = (int) DB::table('loyalty_accounts')
            ->insertGetId([
                'user_id' => $customer->id,
                'identity_state' => 'verified',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return (int) DB::table('loyalty_wallets')
            ->insertGetId([
                'loyalty_account_id' => $account,
                'market_id' => $this->marketId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function test_crm74_directory_excludes_staff(): void
    {
        $staff = $this->staff();

        $customer = $this->customer();

        $this->getJson(self::API . '/customers')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $customer->id);

        $this->getJson(
            self::API . "/customers/{$staff->id}"
        )->assertNotFound();
    }

    public function test_crm74_search_by_email(): void
    {
        $this->staff();

        $customer = $this->customer([
            'email' => 'unique74@example.test',
        ]);

        $this->customer();

        $this->getJson(
            self::API . '/customers?q=unique74'
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $customer->id);
    }

    public function test_crm74_vip_segment_uses_paid_orders(): void
    {
        $this->staff();

        $vip = $this->customer();
        $other = $this->customer();

        for ($i = 0; $i < 5; $i++) {
            $this->order($vip);
            $this->order($other, 'pending');
        }

        $this->getJson(
            self::API . '/customers?segment=vip'
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $vip->id)
            ->assertJsonPath('items.0.paid_count', 5);

        $this->profile($other)
            ->assertOk()
            ->assertJsonPath('customer.segment', 'prospect');
    }

    public function test_crm74_inactive_customer_segment(): void
    {
        $this->staff();

        $customer = $this->customer();

        $this->order($customer, 'paid', 400000, 100);

        $this->profile($customer)
            ->assertOk()
            ->assertJsonPath('customer.segment', 'inactive');
    }

    public function test_crm74_paid_goods_statistics(): void
    {
        $this->staff();

        $customer = $this->customer();

        $this->order($customer, 'paid', 400000);
        $this->order($customer, 'paid', 200000);
        $this->order($customer, 'pending', 900000);

        $this->profile($customer)
            ->assertOk()
            ->assertJsonPath('customer.orders_count', 3)
            ->assertJsonPath('customer.paid_count', 2)
            ->assertJsonPath('customer.segment', 'regular')
            ->assertJsonPath(
                'statistics.currencies.0.goods_total_minor',
                '600000'
            )
            ->assertJsonPath(
                'statistics.currencies.0.average_goods_minor',
                '300000'
            );
    }

    public function test_crm74_customer_order_history_is_isolated(): void
    {
        $this->staff();

        $first = $this->customer();
        $second = $this->customer();

        $firstOrder = $this->order($first);

        $this->order($second);

        $this->getJson(
            self::API . "/customers/{$first->id}/orders"
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $firstOrder)
            ->assertJsonPath(
                'items.0.goods_payable_minor',
                '400000'
            );
    }

    public function test_crm74_guest_orders_are_not_assigned_by_guess(): void
    {
        $this->staff();

        $customer = $this->customer();

        $this->order(null);

        $this->profile($customer)
            ->assertOk()
            ->assertJsonPath('customer.orders_count', 0);

        $this->getJson(self::API . '/overview')
            ->assertOk()
            ->assertJsonPath('guest_orders_count', 1);
    }

    public function test_crm74_contact_is_encrypted_and_readable(): void
    {
        $this->staff();

        $customer = $this->customer();

        $id = $this->write(
            $customer,
            $this->contact()
        )->assertCreated()->json('event_id');

        $row = DB::table(
            'checkout_crm_customer_events74'
        )->where('id', $id)->first();

        $this->assertStringNotContainsString(
            '+380501234567',
            $row->payload_ciphertext
        );

        $this->assertStringNotContainsString(
            'Тестова адреса',
            $row->payload_ciphertext
        );

        $this->profile($customer)
            ->assertOk()
            ->assertJsonPath(
                'contact.phone',
                '+380501234567'
            )
            ->assertJsonPath(
                'contact.contact_id',
                $id
            );
    }

    public function test_crm74_stale_contact_edit_is_rejected(): void
    {
        $this->staff();

        $customer = $this->customer();

        $id = $this->write(
            $customer,
            $this->contact()
        )->assertCreated()->json('event_id');

        $this->write(
            $customer,
            $this->contact([
                'city' => 'Київ',
            ])
        )->assertStatus(409);

        $this->write(
            $customer,
            $this->contact([
                'expected_contact_id' => $id,
                'city' => 'Київ',
            ])
        )->assertCreated();

        $this->profile($customer)
            ->assertOk()
            ->assertJsonPath('contact.city', 'Київ');
    }

    public function test_crm74_contact_retry_is_idempotent(): void
    {
        $this->staff();

        $customer = $this->customer();

        $body = $this->contact();

        $id = $this->write($customer, $body)
            ->assertCreated()
            ->json('event_id');

        $this->write($customer, $body)
            ->assertCreated()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('event_id', $id);

        $this->assertDatabaseCount(
            'checkout_crm_customer_events74',
            1
        );
    }

    public function test_crm74_interaction_history(): void
    {
        $staff = $this->staff();

        $customer = $this->customer();

        $body = [
            'operation_key' => (string) Str::uuid(),
            'kind' => 'call',
            'body' => 'Покупатель уточнил размер обуви.',
            'follow_up_at' => null,
        ];

        $this->write($customer, $body)
            ->assertCreated();

        $this->write($customer, $body)
            ->assertCreated()
            ->assertJsonPath('replayed', true);

        $this->getJson(
            self::API . "/customers/{$customer->id}/history"
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.kind', 'call')
            ->assertJsonPath('items.0.actor_id', $staff->id)
            ->assertJsonPath(
                'items.0.payload.body',
                $body['body']
            );
    }

    public function test_crm74_operation_key_cannot_be_reused_for_another_customer(): void
    {
        $this->staff();

        $first = $this->customer();
        $second = $this->customer();

        $body = $this->contact();

        $this->write($first, $body)
            ->assertCreated();

        $this->write($second, $body)
            ->assertStatus(409);
    }

    public function test_crm74_wallet_and_ledger_are_customer_scoped(): void
    {
        $this->staff();

        $first = $this->customer();
        $second = $this->customer();

        $wallet = $this->wallet($first);

        $otherWallet = $this->wallet($second);

        $this->getJson(
            self::API . "/customers/{$first->id}/wallets"
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $wallet)
            ->assertJsonPath(
                'items.0.balance.spendable_units',
                0
            );

        $this->getJson(
            self::API
            . "/customers/{$first->id}/wallets/{$wallet}/ledger"
        )->assertOk()->assertJsonCount(0, 'items');

        $this->getJson(
            self::API
            . "/customers/{$first->id}/wallets/{$otherWallet}/ledger"
        )->assertNotFound();
    }

    public function test_crm74_support_cannot_read_financial_totals(): void
    {
        $this->staff('support');

        $customer = $this->customer();

        $this->order($customer);

        $this->profile($customer)
            ->assertOk()
            ->assertJsonPath(
                'statistics.financial_restricted',
                true
            )
            ->assertJsonCount(
                0,
                'statistics.currencies'
            );

        $this->getJson(
            self::API . "/customers/{$customer->id}/orders"
        )->assertOk()
            ->assertJsonPath(
                'items.0.goods_payable_minor',
                null
            );

        $this->getJson(
            self::API . "/customers/{$customer->id}/access"
        )->assertForbidden();
    }

    public function test_crm74_finance_is_read_only(): void
    {
        $this->staff('finance');

        $customer = $this->customer();

        $this->profile($customer)
            ->assertOk()
            ->assertJsonPath('can_manage', false);

        $this->write(
            $customer,
            $this->contact()
        )->assertForbidden();
    }

    public function test_crm74_view_access_is_audited(): void
    {
        $staff = $this->staff();

        $customer = $this->customer();

        $this->profile($customer)
            ->assertOk();

        $this->getJson(
            self::API . "/customers/{$customer->id}/access"
        )->assertOk()
            ->assertJsonPath('items.0.kind', 'view')
            ->assertJsonPath('items.0.actor_id', $staff->id)
            ->assertJsonPath(
                'items.0.payload.section',
                'profile'
            );
    }

    public function test_crm74_audit_is_immutable(): void
    {
        $this->staff();

        $customer = $this->customer();

        $this->write(
            $customer,
            $this->contact()
        )->assertCreated();

        $this->expectException(QueryException::class);

        DB::table('checkout_crm_customer_events74')
            ->where('customer_id', $customer->id)
            ->update(['kind' => 'note']);
    }

    public function test_crm74_customer_token_is_forbidden(): void
    {
        $customer = $this->customer();

        Auth::forgetGuards();

        $this->withToken(
            $customer->createToken(
                'customer',
                ['crm65:access']
            )->plainTextToken
        );

        $this->getJson(self::API . '/customers')
            ->assertForbidden();
    }

    public function test_crm74_revoked_staff_cannot_read(): void
    {
        $staff = $this->staff();

        DB::table('checkout_crm_staff65')
            ->where('user_id', $staff->id)
            ->update(['active' => false]);

        $this->getJson(self::API . '/customers')
            ->assertForbidden();
    }

    public function test_crm74_feature_flag_is_enforced(): void
    {
        $this->staff();

        config()->set('crm74.enabled', false);

        $this->getJson(self::API . '/customers')
            ->assertStatus(503);
    }

    public function test_crm74_requires_authentication(): void
    {
        $this->getJson(self::API . '/customers')
            ->assertUnauthorized();
    }
}
