<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Crm67InboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new \RuntimeException('Requires PostgreSQL boardriders_test');
        }
        config()->set('crm65.enabled', true);
        config()->set('crm66.enabled', true);
        config()->set('crm67.enabled', true);
    }

    /** @return array{0: string, 1: int} */
    private function staff(string $role = 'manager'): array
    {
        $email = strtolower((string) Str::uuid()) . '@example.test';
        $user = User::query()->create([
            'name' => 'CRM67 ' . $role,
            'email' => $email,
            'password' => Hash::make('Crm67TestPassword!'),
        ]);
        $user->email_verified_at = now();
        $user->save();
        DB::table('checkout_crm_staff65')->insert([
            'user_id' => (int) $user->id,
            'role' => $role,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson('/api/v1/admin/crm65/session', [
            'email' => $email,
            'password' => 'Crm67TestPassword!',
        ])->assertOk()->json('access_token');

        return [(string) $token, (int) $user->id];
    }

    private function order(): string
    {
        $key = (string) Str::uuid();
        DB::table('orders')->insert([
            'order_key' => $key,
            'payment_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $key;
    }

    private function createTask(string $orderKey, string $priority = 'normal'): int
    {
        return (int) $this->postJson("/api/v1/admin/crm66/orders/{$orderKey}/tasks", [
            'operation_key' => (string) Str::uuid(),
            'title' => 'Check customer delivery data',
            'priority' => $priority,
        ])->assertCreated()->json('task_id');
    }

    private function action(string $orderKey, int $taskId, string $action, int $version): void
    {
        $this->postJson("/api/v1/admin/crm66/orders/{$orderKey}/tasks/{$taskId}/action", [
            'operation_key' => (string) Str::uuid(),
            'action' => $action,
            'expected_version' => $version,
        ])->assertOk();
    }

    public function test_cod_crm67_default_inbox_contains_only_my_tasks(): void
    {
        $order = $this->order();
        $this->withToken($this->staff()[0]);
        $id = $this->createTask($order);
        $this->getJson('/api/v1/admin/crm67/inbox')
            ->assertOk()->assertJsonPath('scope', 'mine')
            ->assertJsonPath('summary.total', 0)->assertJsonCount(0, 'items');
        $this->action($order, $id, 'claim', 1);
        $this->getJson('/api/v1/admin/crm67/inbox')
            ->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('items.0.order_key', $order)
            ->assertJsonPath('items.0.task_id', $id)
            ->assertJsonPath('items.0.status', 'open')
            ->assertJsonPath('read_only', true);
    }

    public function test_cod_crm67_unassigned_scope_and_summary(): void
    {
        $this->withToken($this->staff()[0]);
        $order = $this->order();
        $this->createTask($order, 'critical');
        $this->createTask($order, 'low');
        $this->getJson('/api/v1/admin/crm67/inbox?scope=unassigned')
            ->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.unassigned', 2)
            ->assertJsonPath('summary.important_active', 1);
    }

    public function test_cod_crm67_filters_by_status_and_priority(): void
    {
        $this->withToken($this->staff()[0]);
        $order = $this->order();
        $first = $this->createTask($order, 'high');
        $this->createTask($order, 'low');
        $this->action($order, $first, 'claim', 1);
        $this->action($order, $first, 'start', 2);

        $this->getJson('/api/v1/admin/crm67/inbox?scope=all&status=in_progress&priority=high')
            ->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.task_id', $first)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.in_progress', 1)
            ->assertJsonPath('summary.open', 1);
        $this->getJson('/api/v1/admin/crm67/inbox?scope=all&status=resolved')
            ->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_cod_crm67_cursor_paginates_without_duplicates(): void
    {
        $this->withToken($this->staff()[0]);
        $order = $this->order();
        $this->createTask($order);
        $this->createTask($order);
        $this->createTask($order);

        $first = $this->getJson('/api/v1/admin/crm67/inbox?scope=all&limit=2')
            ->assertOk()->assertJsonCount(2, 'items')->json();
        $this->assertNotNull($first['next_before_id']);
        $second = $this->getJson('/api/v1/admin/crm67/inbox?scope=all&limit=2&before_id=' . $first['next_before_id'])
            ->assertOk()->assertJsonCount(1, 'items')->json();
        $this->assertNull($second['next_before_id']);
        $ids = array_merge(
            array_column($first['items'], 'task_id'),
            array_column($second['items'], 'task_id')
        );
        $this->assertCount(3, array_unique($ids));
    }

    public function test_cod_crm67_can_open_tasks_across_different_orders(): void
    {
        $this->withToken($this->staff()[0]);
        $first = $this->order();
        $second = $this->order();
        $this->createTask($first);
        $this->createTask($second);
        $items = $this->getJson('/api/v1/admin/crm67/inbox?scope=all')
            ->assertOk()->json('items');
        $this->assertEqualsCanonicalizing([$first, $second], array_column($items, 'order_key'));
        $this->assertSame('pending', DB::table('orders')->where('order_key', $first)->value('payment_status'));
    }

    public function test_cod_crm67_viewer_cannot_open_global_inbox(): void
    {
        $this->withToken($this->staff('viewer')[0]);
        $this->getJson('/api/v1/admin/crm67/inbox?scope=all')->assertForbidden();
    }

    public function test_cod_crm67_finance_cannot_open_global_inbox(): void
    {
        $this->withToken($this->staff('finance')[0]);
        $this->getJson('/api/v1/admin/crm67/inbox?scope=mine')->assertForbidden();
    }

    public function test_cod_crm67_support_can_view_unassigned_queue(): void
    {
        $this->withToken($this->staff('support')[0]);
        $order = $this->order();
        $this->createTask($order);
        $this->getJson('/api/v1/admin/crm67/inbox?scope=unassigned')
            ->assertOk()->assertJsonPath('summary.total', 1);
    }

    public function test_cod_crm67_rejects_unexpected_filters(): void
    {
        $this->withToken($this->staff()[0]);
        $this->getJson('/api/v1/admin/crm67/inbox?scope=somebody_else')->assertUnprocessable();
        $this->getJson('/api/v1/admin/crm67/inbox?before_id=-1')->assertUnprocessable();
        $this->getJson('/api/v1/admin/crm67/inbox?limit=101')->assertUnprocessable();
    }

    public function test_cod_crm67_projection_tampering_blocks_inbox(): void
    {
        $this->withToken($this->staff()[0]);
        $order = $this->order();
        $id = $this->createTask($order);
        DB::table('checkout_crm_tasks66')->where('id', $id)
            ->update(['title' => 'Tampered customer delivery data']);
        $this->getJson('/api/v1/admin/crm67/inbox?scope=all')
            ->assertStatus(503)
            ->assertJsonPath('message', 'CRM inbox integrity check failed');
    }

    public function test_cod_crm67_revoked_employee_cannot_open_queue(): void
    {
        [$token, $userId] = $this->staff();
        $this->withToken($token);
        DB::table('checkout_crm_staff65')->where('user_id', $userId)
            ->update(['active' => false]);
        $this->getJson('/api/v1/admin/crm67/inbox')->assertForbidden();
    }

    public function test_cod_crm67_feature_flag_fails_closed(): void
    {
        $this->withToken($this->staff()[0]);
        config()->set('crm67.enabled', false);
        $this->getJson('/api/v1/admin/crm67/inbox')->assertStatus(503);
    }

    public function test_cod_crm67_depends_on_crm66_enabled(): void
    {
        $this->withToken($this->staff()[0]);
        config()->set('crm66.enabled', false);
        $this->getJson('/api/v1/admin/crm67/inbox')->assertStatus(503);
    }

    public function test_cod_crm67_route_requires_sanctum(): void
    {
        $this->getJson('/api/v1/admin/crm67/inbox')->assertUnauthorized();
        $route = Route::getRoutes()->getByName('crm67.inbox.index');
        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
    }
}
