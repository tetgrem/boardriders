<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Crm68StaffTest extends TestCase
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
        config()->set('crm68.enabled', true);
    }

    private function user(bool $verified = true): User
    {
        $user = User::query()->create([
            'name' => 'Employee Test',
            'email' => Str::lower((string) Str::uuid()) . '@example.test',
            'password' => Hash::make('Long-CRM68-test-password'),
        ]);
        if ($verified) {
            $user->email_verified_at = now();
            $user->save();
        }
        return $user;
    }

    private function staff(string $role = 'owner'): User
    {
        $user = $this->user();
        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id, 'role' => $role, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function loginAs(User $user): string
    {
        $token = $user->createToken('crm65-dashboard', ['crm65:access'])->plainTextToken;
        $this->withToken($token);
        return $token;
    }

    private function grantBody(User $target, string $role = 'support', ?string $operationKey = null): array
    {
        return [
            'email' => $target->email,
            'role' => $role,
            'operation_key' => $operationKey ?? (string) Str::uuid(),
        ];
    }

    private function changeBody(string $action, int $version, ?string $role = null, ?string $operationKey = null): array
    {
        return [
            'action' => $action,
            'role' => $role,
            'expected_version' => $version,
            'operation_key' => $operationKey ?? (string) Str::uuid(),
        ];
    }

    public function test_cod_crm68_owner_can_grant_existing_verified_user_and_list(): void
    {
        $owner = $this->staff();
        $target = $this->user();
        $this->loginAs($owner);
        $this->postJson('/api/v1/admin/crm68/staff', $this->grantBody($target))
            ->assertCreated()->assertJsonPath('member.role', 'support')
            ->assertJsonPath('member.version', 1);
        $this->getJson('/api/v1/admin/crm68/staff')->assertOk()
            ->assertJsonPath('items.0.user_id', $target->id)
            ->assertJsonPath('items.0.email', $target->email);
        $this->assertDatabaseCount('checkout_crm_staff_events68', 1);
    }

    public function test_cod_crm68_repeated_grant_is_idempotent(): void
    {
        $this->loginAs($this->staff());
        $target = $this->user();
        $body = $this->grantBody($target);
        $this->postJson('/api/v1/admin/crm68/staff', $body)->assertCreated();
        $this->postJson('/api/v1/admin/crm68/staff', $body)
            ->assertCreated()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('checkout_crm_staff_events68', 1);
    }

    public function test_cod_crm68_operation_key_cannot_be_reused_for_different_role(): void
    {
        $this->loginAs($this->staff());
        $target = $this->user();
        $body = $this->grantBody($target);
        $this->postJson('/api/v1/admin/crm68/staff', $body)->assertCreated();
        $body['role'] = 'finance';
        $this->postJson('/api/v1/admin/crm68/staff', $body)->assertStatus(409);
        $this->assertDatabaseCount('checkout_crm_staff_events68', 1);
    }

    public function test_cod_crm68_cannot_grant_unverified_account(): void
    {
        $this->loginAs($this->staff());
        $target = $this->user(false);
        $this->postJson('/api/v1/admin/crm68/staff', $this->grantBody($target))
            ->assertStatus(409);
        $this->assertDatabaseCount('checkout_crm_staff_events68', 0);
    }

    public function test_cod_crm68_manager_cannot_list_or_manage_staff(): void
    {
        $this->loginAs($this->staff('manager'));
        $target = $this->user();
        $this->getJson('/api/v1/admin/crm68/staff')->assertForbidden();
        $this->postJson('/api/v1/admin/crm68/staff', $this->grantBody($target))
            ->assertForbidden();
    }

    public function test_cod_crm68_role_change_revokes_existing_crm_session(): void
    {
        $owner = $this->staff();
        $target = $this->staff('support');
        $token = $target->createToken('crm65-dashboard', ['crm65:access'])->plainTextToken;
        $this->loginAs($owner);
        $this->postJson("/api/v1/admin/crm68/staff/{$target->id}/change",
            $this->changeBody('role_changed', 1, 'viewer'))
            ->assertOk()->assertJsonPath('member.role', 'viewer')
            ->assertJsonPath('member.version', 2);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => 'crm65-dashboard',
            'tokenable_id' => $target->id,
        ]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/crm65/me')->assertUnauthorized();
    }

    public function test_cod_crm68_deactivate_and_reactivate_with_versions(): void
    {
        $owner = $this->staff();
        $target = $this->staff('support');
        $this->loginAs($owner);
        $this->postJson("/api/v1/admin/crm68/staff/{$target->id}/change",
            $this->changeBody('deactivated', 1))
            ->assertOk()->assertJsonPath('member.active', false)
            ->assertJsonPath('member.version', 2);
        $this->postJson("/api/v1/admin/crm68/staff/{$target->id}/change",
            $this->changeBody('reactivated', 2))
            ->assertOk()->assertJsonPath('member.active', true)
            ->assertJsonPath('member.version', 3);
        $this->assertDatabaseCount('checkout_crm_staff_events68', 2);
    }

    public function test_cod_crm68_owner_cannot_change_own_access(): void
    {
        $owner = $this->staff();
        $this->loginAs($owner);
        $this->postJson("/api/v1/admin/crm68/staff/{$owner->id}/change",
            $this->changeBody('deactivated', 1))->assertStatus(409);
        $this->assertDatabaseHas('checkout_crm_staff65', [
            'user_id' => $owner->id,
            'active' => true,
        ]);
    }

    public function test_cod_crm68_stale_version_is_rejected(): void
    {
        $owner = $this->staff();
        $target = $this->staff('support');
        $this->loginAs($owner);
        $this->postJson("/api/v1/admin/crm68/staff/{$target->id}/change",
            $this->changeBody('role_changed', 1, 'finance'))->assertOk();
        $this->postJson("/api/v1/admin/crm68/staff/{$target->id}/change",
            $this->changeBody('deactivated', 1))->assertStatus(409);
        $this->assertDatabaseCount('checkout_crm_staff_events68', 1);
    }

    public function test_cod_crm68_history_contains_signed_changes(): void
    {
        $owner = $this->staff();
        $target = $this->staff('support');
        $this->loginAs($owner);
        $this->postJson("/api/v1/admin/crm68/staff/{$target->id}/change",
            $this->changeBody('role_changed', 1, 'manager'))->assertOk();
        $this->getJson("/api/v1/admin/crm68/staff/{$target->id}/history")
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.action', 'role_changed')
            ->assertJsonPath('0.to_role', 'manager');
    }

    public function test_cod_crm68_detects_tampered_staff_projection(): void
    {
        $owner = $this->staff();
        $target = $this->staff('support');
        $this->loginAs($owner);
        $this->postJson("/api/v1/admin/crm68/staff/{$target->id}/change",
            $this->changeBody('role_changed', 1, 'manager'))->assertOk();
        DB::table('checkout_crm_staff65')->where('user_id', $target->id)
            ->update(['role' => 'viewer']);
        $this->getJson('/api/v1/admin/crm68/staff')->assertStatus(503);
    }

    public function test_cod_crm68_postgresql_prevents_audit_rewrite(): void
    {
        $this->loginAs($this->staff());
        $target = $this->user();
        $this->postJson('/api/v1/admin/crm68/staff', $this->grantBody($target))
            ->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('checkout_crm_staff_events68')->update(['to_role' => 'viewer']);
    }

    public function test_cod_crm68_disabled_flag_fails_closed(): void
    {
        $this->loginAs($this->staff());
        config()->set('crm68.enabled', false);
        $this->getJson('/api/v1/admin/crm68/staff')->assertStatus(503);
    }

    public function test_cod_crm68_routes_require_sanctum(): void
    {
        $this->getJson('/api/v1/admin/crm68/staff')->assertUnauthorized();
        foreach ([
                     'crm68.staff.index',
                     'crm68.staff.grant',
                     'crm68.staff.change',
                     'crm68.staff.history',
                 ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
