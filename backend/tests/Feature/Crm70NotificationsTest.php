<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Crm70NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/crm70';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new \RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

        config()->set('crm64.enabled', true);
        config()->set('crm65.enabled', true);
        config()->set('crm66.enabled', true);
        config()->set('crm69.enabled', true);
        config()->set('crm70.enabled', true);
    }

    private function staff(string $role = 'owner'): array
    {
        $user = User::query()->create([
            'name' => 'CRM70 ' . $role,
            'email' => (string) Str::uuid() . '@example.test',
            'password' => Hash::make('Secure-CRM70-Test-Password'),
        ]);

        $user->email_verified_at = now();
        $user->save();

        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $user->createToken(
            'crm65-dashboard',
            ['crm65:access']
        )->plainTextToken;

        return [$user, $token];
    }

    private function login(string $token): void
    {
        Auth::forgetGuards();

        $this->withToken($token);
    }

    private function order(
        string $status = 'pending',
        int $ageDays = 0
    ): array {
        $key = (string) Str::uuid();

        $id = DB::table('orders')->insertGetId([
            'order_key' => $key,
            'payment_status' => $status,
            'created_at' => now()->subDays($ageDays),
            'updated_at' => now(),
        ]);

        return [(int) $id, $key];
    }

    public function test_cod_crm70_new_order_appears_in_feed(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        [$id, $key] = $this->order();

        $this->getJson(self::BASE . '/notifications')
            ->assertOk()
            ->assertJsonPath(
                'items.0.notification_key',
                "orders:{$id}"
            )
            ->assertJsonPath('items.0.order_key', $key)
            ->assertJsonPath('items.0.is_read', false)
            ->assertJsonPath('summary.unread', 1);
    }

    public function test_cod_crm70_old_orders_are_not_new_notifications(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        $this->order('pending', 10);

        $this->getJson(self::BASE . '/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('summary.unread', 0);
    }

    public function test_cod_crm70_read_state_is_personal(): void
    {
        [$first, $firstToken] = $this->staff();
        [, $secondToken] = $this->staff();

        [$id] = $this->order();

        $key = "orders:{$id}";

        $this->login($firstToken);

        $this->postJson(self::BASE . '/read', [
            'notification_key' => $key,
        ])->assertOk()
            ->assertJsonPath('is_read', true);

        $this->getJson(self::BASE . '/count')
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->login($secondToken);

        $this->getJson(self::BASE . '/count')
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->assertDatabaseHas(
            'checkout_crm_notification_reads70',
            [
                'user_id' => $first->id,
                'notification_key' => $key,
            ]
        );
    }

    public function test_cod_crm70_mark_read_is_idempotent(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        [$id] = $this->order();

        $payload = [
            'notification_key' => "orders:{$id}",
        ];

        $first = $this->postJson(
            self::BASE . '/read',
            $payload
        )->assertOk()->json('read_at');

        $second = $this->postJson(
            self::BASE . '/read',
            $payload
        )->assertOk()->json('read_at');

        $this->assertSame($first, $second);

        $this->assertDatabaseCount(
            'checkout_crm_notification_reads70',
            1
        );
    }

    public function test_cod_crm70_notification_can_be_marked_unread(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        [$id] = $this->order();

        $payload = [
            'notification_key' => "orders:{$id}",
        ];

        $this->postJson(
            self::BASE . '/read',
            $payload
        )->assertOk();

        $this->postJson(
            self::BASE . '/unread',
            $payload
        )->assertOk()
            ->assertJsonPath('is_read', false);

        $this->getJson(self::BASE . '/count')
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->assertDatabaseCount(
            'checkout_crm_notification_reads70',
            0
        );
    }

    public function test_cod_crm70_unread_filter_hides_read_items(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        [$first] = $this->order();
        [$second] = $this->order();

        $this->postJson(self::BASE . '/read', [
            'notification_key' => "orders:{$first}",
        ])->assertOk();

        $this->getJson(
            self::BASE . '/notifications?unread=1'
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath(
                'items.0.notification_key',
                "orders:{$second}"
            );
    }

    public function test_cod_crm70_finance_can_see_payment_review(): void
    {
        [, $token] = $this->staff('finance');

        $this->login($token);

        [$id] = $this->order('review');

        $this->getJson(
            self::BASE . '/notifications?category=payments'
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath(
                'items.0.notification_key',
                "payments:{$id}"
            )
            ->assertJsonPath(
                'items.0.severity',
                'critical'
            )
            ->assertJsonPath('summary.critical', 1);
    }

    public function test_cod_crm70_support_cannot_read_financial_notifications(): void
    {
        [, $token] = $this->staff('support');

        $this->login($token);

        [$id] = $this->order('review');

        $this->getJson(
            self::BASE . '/notifications?category=payments'
        )->assertForbidden();

        $this->postJson(self::BASE . '/read', [
            'notification_key' => "payments:{$id}",
        ])->assertNotFound();

        $this->getJson(self::BASE . '/notifications')
            ->assertOk()
            ->assertJsonMissingPath('preferences.payments')
            ->assertJsonPath('summary.critical', 0);
    }

    public function test_cod_crm70_viewer_has_no_restricted_categories(): void
    {
        [, $token] = $this->staff('viewer');

        $this->login($token);

        $this->order();

        $this->getJson(self::BASE . '/notifications')
            ->assertOk()
            ->assertJsonPath('preferences.orders', true)
            ->assertJsonMissingPath('preferences.payments')
            ->assertJsonMissingPath('preferences.returns')
            ->assertJsonMissingPath('preferences.delivery')
            ->assertJsonMissingPath('preferences.fiscal')
            ->assertJsonMissingPath('preferences.sla');
    }

    public function test_cod_crm70_preferences_can_disable_category(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        $this->order();

        $this->postJson(self::BASE . '/preference', [
            'category' => 'orders',
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('preferences.orders', false);

        $this->getJson(self::BASE . '/count')
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->postJson(self::BASE . '/preference', [
            'category' => 'orders',
            'enabled' => true,
        ])->assertOk();

        $this->getJson(self::BASE . '/count')
            ->assertOk()
            ->assertJsonPath('unread', 1);
    }

    public function test_cod_crm70_preferences_are_personal(): void
    {
        [, $firstToken] = $this->staff();
        [, $secondToken] = $this->staff();

        $this->order();

        $this->login($firstToken);

        $this->postJson(self::BASE . '/preference', [
            'category' => 'orders',
            'enabled' => false,
        ])->assertOk();

        $this->login($secondToken);

        $this->getJson(self::BASE . '/notifications')
            ->assertOk()
            ->assertJsonPath('preferences.orders', true)
            ->assertJsonPath('summary.unread', 1);
    }

    public function test_cod_crm70_cannot_enable_forbidden_category(): void
    {
        [, $token] = $this->staff('viewer');

        $this->login($token);

        $this->postJson(self::BASE . '/preference', [
            'category' => 'payments',
            'enabled' => true,
        ])->assertForbidden();

        $this->assertDatabaseCount(
            'checkout_crm_notification_preferences70',
            0
        );
    }

    public function test_cod_crm70_overdue_task_appears_in_feed(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        [, $orderKey] = $this->order();

        $taskId = (int) $this->postJson(
            "/api/v1/admin/crm66/orders/{$orderKey}/tasks",
            [
                'operation_key' => (string) Str::uuid(),
                'title' => 'Check delivery information',
                'priority' => 'high',
            ]
        )->assertCreated()->json('task_id');

        $this->postJson(
            "/api/v1/admin/crm69/tasks/{$taskId}/deadline",
            [
                'operation_key' => (string) Str::uuid(),
                'expected_revision' => 0,
                'due_at' => now('UTC')
                    ->addHour()
                    ->format('Y-m-d\TH:i:s\Z'),
            ]
        )->assertOk();

        $this->getJson(
            self::BASE . '/notifications?category=sla'
        )->assertOk()
            ->assertJsonCount(0, 'items');

        $this->travel(2)->hours();

        try {
            $this->getJson(
                self::BASE . '/notifications?category=sla'
            )->assertOk()
                ->assertJsonCount(1, 'items')
                ->assertJsonPath(
                    'items.0.notification_key',
                    "sla:{$taskId}:1"
                );
        } finally {
            $this->travelBack();
        }
    }

    public function test_cod_crm70_read_does_not_change_order(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        [$id] = $this->order('review');

        $this->postJson(self::BASE . '/read', [
            'notification_key' => "payments:{$id}",
        ])->assertOk();

        $this->assertSame(
            'review',
            DB::table('orders')
                ->where('id', $id)
                ->value('payment_status')
        );
    }

    public function test_cod_crm70_pagination_has_no_duplicate_items(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        $this->order();
        $this->order();
        $this->order();

        $first = $this->getJson(
            self::BASE . '/notifications?limit=2&page=1'
        )->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('has_more', true)
            ->json('items');

        $second = $this->getJson(
            self::BASE . '/notifications?limit=2&page=2'
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('has_more', false)
            ->json('items');

        $keys = array_merge(
            array_column($first, 'notification_key'),
            array_column($second, 'notification_key')
        );

        $this->assertCount(3, array_unique($keys));
    }

    public function test_cod_crm70_rejects_invalid_input(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        $this->getJson(
            self::BASE . '/notifications?limit=500'
        )->assertUnprocessable();

        $this->getJson(
            self::BASE . '/notifications?page=0'
        )->assertUnprocessable();

        $this->postJson(self::BASE . '/read', [
            'notification_key' => 'invalid',
        ])->assertUnprocessable();

        $this->postJson(self::BASE . '/read', [
            'notification_key' => 'orders:999999999',
        ])->assertNotFound();
    }

    public function test_cod_crm70_customer_token_is_denied(): void
    {
        [$user] = $this->staff();

        $token = $user->createToken(
            'customer',
            ['customer:access']
        )->plainTextToken;

        $this->login($token);

        $this->getJson(self::BASE . '/notifications')
            ->assertForbidden();
    }

    public function test_cod_crm70_revoked_employee_is_denied(): void
    {
        [$user, $token] = $this->staff();

        DB::table('checkout_crm_staff65')
            ->where('user_id', $user->id)
            ->update(['active' => false]);

        $this->login($token);

        $this->getJson(self::BASE . '/notifications')
            ->assertForbidden();
    }

    public function test_cod_crm70_disabled_flag_fails_closed(): void
    {
        [, $token] = $this->staff();

        $this->login($token);

        config()->set('crm70.enabled', false);

        $this->getJson(self::BASE . '/notifications')
            ->assertStatus(503);

        $this->getJson(self::BASE . '/count')
            ->assertStatus(503);
    }

    public function test_cod_crm70_routes_require_sanctum(): void
    {
        $this->getJson(self::BASE . '/notifications')
            ->assertUnauthorized();

        foreach ([
                     'crm70.notifications.index',
                     'crm70.notifications.count',
                     'crm70.notifications.read',
                     'crm70.notifications.unread',
                     'crm70.notifications.preference',
                 ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route);

            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware()
            );
        }
    }
}
