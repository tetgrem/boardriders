<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Tests\TestCase;

final class Crm66TasksTest extends TestCase
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
    }

    private function employee(string $role = 'manager'): string
    {
        $email = strtolower((string) Str::uuid()) . '@example.test';
        $user = User::query()->create([
            'name' => 'CRM66 Employee', 'email' => $email,
            'password' => Hash::make('Crm66Password!'),
        ]);
        $user->email_verified_at = now();
        $user->save();
        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id, 'role' => $role, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $login = $this->postJson('/api/v1/admin/crm65/session', [
            'email' => $email, 'password' => 'Crm66Password!',
        ])->assertOk();
        return (string) $login->json('access_token');
    }

    private function order(): string
    {
        $key = (string) Str::uuid();
        DB::table('orders')->insert([
            'order_key' => $key, 'payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $key;
    }

    private function create(string $order, ?string $key = null, string $title = 'Contact the customer'): array
    {
        return [
            'operation_key' => $key ?? (string) Str::uuid(),
            'title' => $title,
            'priority' => 'high',
        ];
    }

    private function transition(string $action, int $version, ?string $key = null): array
    {
        return [
            'operation_key' => $key ?? (string) Str::uuid(),
            'action' => $action, 'expected_version' => $version,
        ];
    }

    public function test_cod_crm66_manager_can_create_and_list_task_without_changing_order(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        $created = $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $this->create($order))
            ->assertCreated()->assertJsonPath('status', 'open')->json();
        $this->assertSame(1, $created['version']);
        $this->assertNull($created['assigned_to']);
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks")
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.task_id', $created['task_id']);
        $this->assertSame('pending', DB::table('orders')->where('order_key', $order)->value('payment_status'));
        $this->assertDatabaseCount('checkout_crm_task_events66', 1);
    }

    public function test_cod_crm66_create_retry_is_idempotent(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        $data = $this->create($order);
        $first = $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $data)->assertCreated()->json();
        $replay = $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $data)
            ->assertCreated()->assertJsonPath('replayed', true)->json();
        $this->assertSame($first['task_id'], $replay['task_id']);
        $this->assertDatabaseCount('checkout_crm_tasks66', 1);
        $this->assertDatabaseCount('checkout_crm_task_events66', 1);
    }

    public function test_cod_crm66_operation_key_cannot_be_reused_for_different_task(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        $data = $this->create($order);
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $data)->assertCreated();
        $data['title'] = 'Different task content';
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $data)->assertStatus(409);
        $this->assertDatabaseCount('checkout_crm_tasks66', 1);
    }

    public function test_cod_crm66_task_has_versioned_workflow_and_signed_history(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        $created = $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $this->create($order))
            ->assertCreated()->json();
        $id = $created['task_id'];
        foreach ([
                     ['claim', 1, 'open'], ['start', 2, 'in_progress'],
                     ['resolve', 3, 'resolved'], ['reopen', 4, 'open'],
                 ] as [$action, $version, $status]) {
            $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks/{$id}/action",
                $this->transition($action, $version))
                ->assertOk()->assertJsonPath('status', $status)
                ->assertJsonPath('version', $version + 1);
        }
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks/{$id}/history")
            ->assertOk()->assertJsonCount(5)
            ->assertJsonPath('4.action', 'reopened');
        $this->assertDatabaseCount('checkout_crm_task_events66', 5);
    }

    public function test_cod_crm66_stale_revision_is_rejected_but_exact_retry_is_safe(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        $id = $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $this->create($order))
            ->assertCreated()->json('task_id');
        $body = $this->transition('claim', 1);
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks/{$id}/action", $body)
            ->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks/{$id}/action", $body)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks/{$id}/action",
            $this->transition('start', 1))->assertStatus(409);
        $this->assertDatabaseCount('checkout_crm_task_events66', 2);
    }

    public function test_cod_crm66_viewer_can_read_but_cannot_mutate(): void
    {
        $order = $this->order();
        $this->withToken($this->employee('viewer'));
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks")->assertOk();
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $this->create($order))
            ->assertForbidden();
    }

    public function test_cod_crm66_finance_role_cannot_manage_tasks(): void
    {
        $order = $this->order();
        $this->withToken($this->employee('finance'));
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $this->create($order))
            ->assertForbidden();
    }

    public function test_cod_crm66_other_order_cannot_access_task(): void
    {
        $first = $this->order();
        $second = $this->order();
        $this->withToken($this->employee());
        $id = $this->postJson("/api/v1/admin/crm66/orders/{$first}/tasks", $this->create($first))
            ->assertCreated()->json('task_id');
        $this->getJson("/api/v1/admin/crm66/orders/{$second}/tasks/{$id}/history")
            ->assertStatus(409);
        $this->postJson("/api/v1/admin/crm66/orders/{$second}/tasks/{$id}/action",
            $this->transition('claim', 1))->assertStatus(409);
    }

    public function test_cod_crm66_tampered_projection_is_detected(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        $id = $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $this->create($order))
            ->assertCreated()->json('task_id');
        DB::table('checkout_crm_tasks66')->where('id', $id)->update(['priority' => 'critical']);
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks")->assertStatus(503);
    }

    public function test_cod_crm66_database_prevents_audit_event_rewrite(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        $this->postJson("/api/v1/admin/crm66/orders/{$order}/tasks", $this->create($order))
            ->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('checkout_crm_task_events66')->update(['status' => 'resolved']);
    }

    public function test_cod_crm66_feature_flag_fails_closed(): void
    {
        $order = $this->order();
        $this->withToken($this->employee());
        config()->set('crm66.enabled', false);
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks")->assertStatus(503);
    }

    public function test_cod_crm66_routes_require_sanctum(): void
    {
        $order = $this->order();
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks")->assertUnauthorized();
        foreach (['crm66.tasks.index', 'crm66.tasks.create', 'crm66.tasks.action', 'crm66.tasks.history'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
