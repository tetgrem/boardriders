<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Crm69SlaTest extends TestCase
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
        config()->set('crm69.enabled', true);
    }

    private function staff(string $role = 'manager'): array
    {
        $user = User::query()->create([
            'name' => 'CRM69 ' . $role,
            'email' => (string) Str::uuid() . '@example.test',
            'password' => Hash::make('Secure-Test-Pass-69'),
        ]);
        $user->email_verified_at = now();
        $user->save();
        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id, 'role' => $role, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $user->createToken('crm65-dashboard', ['crm65:access'])->plainTextToken;
        return [$user, $token];
    }

    private function task(): array
    {
        $orderKey = (string) Str::uuid();
        DB::table('orders')->insert([
            'order_key' => $orderKey, 'payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (int) $this->postJson("/api/v1/admin/crm66/orders/{$orderKey}/tasks", [
            'operation_key' => (string) Str::uuid(),
            'title' => 'Call customer to verify delivery',
            'priority' => 'high',
        ])->assertCreated()->json('task_id');
        return [$id, $orderKey];
    }

    private function due(int $revision, ?string $date, ?string $key = null): array
    {
        return [
            'operation_key' => $key ?? (string) Str::uuid(),
            'due_at' => $date,
            'expected_revision' => $revision,
        ];
    }

    private function future(int $hours = 48): string
    {
        return now('UTC')->addHours($hours)->startOfMinute()->format('Y-m-d\TH:i:s\Z');
    }

    public function test_cod_crm69_manager_sets_deadline_and_overview_detects_it(): void
    {
        $this->withToken($this->staff()[1]);
        [$id, $order] = $this->task();
        $due = $this->future();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $this->due(0, $due))
            ->assertOk()->assertJsonPath('revision', 1);
        $response = $this->getJson('/api/v1/admin/crm69/overview?filter=all')
            ->assertOk()->assertJsonPath('items.0.task_id', $id)
            ->assertJsonPath('items.0.sla_revision', 1)
            ->assertJsonPath('summary.active', 1);
        $this->assertSame($order, $response->json('items.0.order_key'));
        $this->assertSame('pending', DB::table('orders')->where('order_key', $order)->value('payment_status'));
    }

    public function test_cod_crm69_due_change_and_clear_are_signed_and_versioned(): void
    {
        $this->withToken($this->staff()[1]);
        [$id] = $this->task();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $this->due(0, $this->future()))->assertOk();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $this->due(1, null))
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('due_at', null);
        $this->getJson("/api/v1/admin/crm69/tasks/{$id}/history")
            ->assertOk()->assertJsonCount(2, 'deadlines');
    }

    public function test_cod_crm69_deadline_retry_is_idempotent(): void
    {
        $this->withToken($this->staff()[1]);
        [$id] = $this->task();
        $body = $this->due(0, $this->future());
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $body)->assertOk();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $body)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('checkout_crm_sla_events69', 1);
    }

    public function test_cod_crm69_deadline_stale_version_is_rejected(): void
    {
        $this->withToken($this->staff()[1]);
        [$id] = $this->task();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $this->due(0, $this->future()))->assertOk();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $this->due(0, $this->future(72)))
            ->assertStatus(409);
    }

    public function test_cod_crm69_support_can_comment_but_cannot_manage_deadline(): void
    {
        $this->withToken($this->staff('support')[1]);
        [$id] = $this->task();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/comment", [
            'operation_key' => (string) Str::uuid(), 'body' => 'Recipient called and informed.',
        ])->assertCreated();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $this->due(0, $this->future()))
            ->assertForbidden();
        $this->getJson("/api/v1/admin/crm69/tasks/{$id}/history")
            ->assertOk()->assertJsonCount(1, 'comments');
    }

    public function test_cod_crm69_comment_retry_is_idempotent(): void
    {
        $this->withToken($this->staff('support')[1]);
        [$id] = $this->task();
        $body = ['operation_key' => (string) Str::uuid(), 'body' => 'Called the customer.'];
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/comment", $body)->assertCreated();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/comment", $body)
            ->assertCreated()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('checkout_crm_task_notes69', 1);
    }

    public function test_cod_crm69_manager_assignment_preserves_crm66_signed_history(): void
    {
        $this->withToken($this->staff('manager')[1]);
        [$id, $order] = $this->task();
        [$target] = $this->staff('support');
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/assign", [
            'operation_key' => (string) Str::uuid(), 'assignee_user_id' => $target->id,
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('task.version', 2)
            ->assertJsonPath('task.assigned_to', $target->id);
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks")
            ->assertOk()->assertJsonPath('items.0.assigned_to', $target->id);
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks/{$id}/history")
            ->assertOk()->assertJsonPath('1.action', 'assigned');
    }

    public function test_cod_crm69_manager_can_reassign_and_unassign_task(): void
    {
        $this->withToken($this->staff('manager')[1]);
        [$id, $order] = $this->task();
        [$first] = $this->staff('support');
        [$second] = $this->staff('manager');
        foreach ([[$first->id, 1], [$second->id, 2], [null, 3]] as [$target, $version]) {
            $this->postJson("/api/v1/admin/crm69/tasks/{$id}/assign", [
                'operation_key' => (string) Str::uuid(),
                'assignee_user_id' => $target, 'expected_version' => $version,
            ])->assertOk()->assertJsonPath('task.version', $version + 1);
        }
        $this->getJson("/api/v1/admin/crm66/orders/{$order}/tasks/{$id}/history")
            ->assertOk()->assertJsonPath('3.action', 'unassigned');
    }

    public function test_cod_crm69_manager_cannot_assign_inactive_employee(): void
    {
        $this->withToken($this->staff('manager')[1]);
        [$id] = $this->task();
        [$target] = $this->staff('support');
        DB::table('checkout_crm_staff65')->where('user_id', $target->id)->update(['active' => false]);
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/assign", [
            'operation_key' => (string) Str::uuid(), 'assignee_user_id' => $target->id,
            'expected_version' => 1,
        ])->assertStatus(409);
    }

    public function test_cod_crm69_support_cannot_reassign(): void
    {
        $this->withToken($this->staff('support')[1]);
        [$id] = $this->task();
        [$target] = $this->staff('support');
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/assign", [
            'operation_key' => (string) Str::uuid(), 'assignee_user_id' => $target->id,
            'expected_version' => 1,
        ])->assertForbidden();
    }

    public function test_cod_crm69_workload_reports_assigned_active_tasks(): void
    {
        [$manager, $token] = $this->staff();
        $this->withToken($token);
        [$id] = $this->task();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/assign", [
            'operation_key' => (string) Str::uuid(), 'assignee_user_id' => $manager->id,
            'expected_version' => 1,
        ])->assertOk();
        $workers = $this->getJson('/api/v1/admin/crm69/overview')
            ->assertOk()->json('workload');
        $this->assertSame(1, $workers[0]['active_tasks']);
    }

    public function test_cod_crm69_tampered_sla_projection_fails_closed(): void
    {
        $this->withToken($this->staff()[1]);
        [$id] = $this->task();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/deadline", $this->due(0, $this->future()))->assertOk();
        DB::table('checkout_crm_task_sla69')->where('task_id', $id)->update(['due_at' => now()->addDays(9)]);
        $this->getJson('/api/v1/admin/crm69/overview')->assertStatus(503);
    }

    public function test_cod_crm69_postgresql_blocks_comment_and_deadline_audit_rewrite(): void
    {
        $this->withToken($this->staff()[1]);
        [$id] = $this->task();
        $this->postJson("/api/v1/admin/crm69/tasks/{$id}/comment", [
            'operation_key' => (string) Str::uuid(), 'body' => 'Immutable entry',
        ])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('checkout_crm_task_notes69')->delete();
    }

    public function test_cod_crm69_finance_role_is_denied(): void
    {
        $this->withToken($this->staff('finance')[1]);
        $this->getJson('/api/v1/admin/crm69/overview')->assertForbidden();
    }

    public function test_cod_crm69_feature_flag_fails_closed(): void
    {
        $this->withToken($this->staff()[1]);
        config()->set('crm69.enabled', false);
        $this->getJson('/api/v1/admin/crm69/overview')->assertStatus(503);
    }

    public function test_cod_crm69_routes_require_sanctum(): void
    {
        $this->getJson('/api/v1/admin/crm69/overview')->assertUnauthorized();
        foreach (['crm69.overview', 'crm69.assignees', 'crm69.history',
                     'crm69.deadline', 'crm69.comment', 'crm69.assign'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
