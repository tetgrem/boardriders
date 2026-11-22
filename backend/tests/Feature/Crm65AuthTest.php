<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Crm65\Crm65Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use Illuminate\Support\Facades\Auth;

final class Crm65AuthTest extends TestCase
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
        config()->set('crm64.enabled', true);
    }

    private function employee(string $role = 'owner', bool $active = true): User
    {
        $user = User::query()->create([
            'name' => 'CRM Staff',
            'email' => 'staff65@example.test',
            'password' => Hash::make('SecurePassword65!'),
        ]);
        $user->email_verified_at = now();
        $user->save();
        DB::table('checkout_crm_staff65')->insert([
            'user_id' => (int) $user->id,
            'role' => $role,
            'active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $user;
    }

    private function login(): string
    {
        $response = $this->postJson('/api/v1/admin/crm65/session', [
            'email' => 'staff65@example.test',
            'password' => 'SecurePassword65!',
        ]);
        $response->assertOk()->assertJsonStructure(['access_token', 'expires_at']);
        return (string) $response->json('access_token');
    }

    public function test_cod_crm65_staff_login_returns_scoped_expiring_token(): void
    {
        $this->employee();
        $token = $this->login();
        $this->assertStringContainsString('|', $token);
        $saved = PersonalAccessToken::query()->firstOrFail();
        $this->assertSame(['crm65:access'], $saved->abilities);
        $this->assertNotNull($saved->expires_at);
    }

    public function test_cod_crm65_customer_without_staff_role_cannot_login(): void
    {
        $user = User::query()->create([
            'name' => 'Buyer', 'email' => 'staff65@example.test',
            'password' => Hash::make('SecurePassword65!'),
        ]);
        $user->email_verified_at = now();
        $user->save();
        $this->postJson('/api/v1/admin/crm65/session', [
            'email' => $user->email, 'password' => 'SecurePassword65!',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_cod_crm65_incorrect_password_does_not_mint_token(): void
    {
        $this->employee();
        $this->postJson('/api/v1/admin/crm65/session', [
            'email' => 'staff65@example.test', 'password' => 'incorrect',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_cod_crm65_inactive_staff_cannot_login(): void
    {
        $this->employee('owner', false);
        $this->postJson('/api/v1/admin/crm65/session', [
            'email' => 'staff65@example.test', 'password' => 'SecurePassword65!',
        ])->assertUnauthorized();
    }

    public function test_cod_crm65_disabled_login_fails_closed(): void
    {
        $this->employee();
        config()->set('crm65.enabled', false);
        $this->postJson('/api/v1/admin/crm65/session', [
            'email' => 'staff65@example.test', 'password' => 'SecurePassword65!',
        ])->assertStatus(503);
    }

    public function test_cod_crm65_owner_can_open_crm64_dashboard(): void
    {
        $this->employee();
        $this->withToken($this->login())
            ->getJson('/api/v1/admin/crm64/dashboard')
            ->assertOk()->assertJsonPath('orders_total', 0);
    }

    public function test_cod_crm65_viewer_cannot_open_crm64_dashboard(): void
    {
        $this->employee('viewer');
        $this->withToken($this->login())
            ->getJson('/api/v1/admin/crm64/dashboard')
            ->assertForbidden();
    }

    public function test_cod_crm65_support_cannot_view_finance_ability(): void
    {
        $user = $this->employee('support');
        $this->withToken($this->login())
            ->getJson('/api/v1/admin/crm65/me')
            ->assertOk()->assertJsonMissing(['crm64.finance.view']);
        $this->assertFalse(app(Crm65Staff::class)->can($user, 'crm64.finance.view'));
    }

    public function test_cod_crm65_me_returns_only_active_staff_permissions(): void
    {
        $this->employee('finance');
        $this->withToken($this->login())->getJson('/api/v1/admin/crm65/me')
            ->assertOk()->assertJsonPath('role', 'finance')
            ->assertJsonFragment(['crm64.finance.view']);
    }

    public function test_cod_crm65_revoking_staff_blocks_existing_token(): void
    {
        $user = $this->employee();
        $token = $this->login();
        DB::table('checkout_crm_staff65')
            ->where('user_id', $user->id)->update(['active' => false]);
        $this->withToken($token)->getJson('/api/v1/admin/crm65/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/crm64/orders')->assertForbidden();
    }

    public function test_cod_crm65_logout_revokes_active_token(): void
    {
        $this->employee();

        $token = $this->login();

        // Logout must revoke the actual Sanctum token.
        $this->withToken($token)
            ->deleteJson(
                '/api/v1/admin/crm65/session'
            )
            ->assertOk()
            ->assertJsonPath('logged_out', true);

        $this->assertDatabaseCount(
            'personal_access_tokens',
            0
        );

        // Simulate a fresh HTTP request.
        // PHPUnit reuses the application and may cache
        // the previously authenticated guard user.
        Auth::forgetGuards();

        // A revoked token must no longer authenticate.
        $this->withToken($token)
            ->getJson(
                '/api/v1/admin/crm65/me'
            )
            ->assertUnauthorized();

        // CRM64 must also reject the revoked token.
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson(
                '/api/v1/admin/crm64/orders'
            )
            ->assertUnauthorized();
    }

    public function test_cod_crm65_token_without_scope_rejected(): void
    {
        $user = $this->employee();
        $token = $user->createToken('foreign-api', ['other:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/crm65/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/crm64/orders')->assertForbidden();
    }

    public function test_cod_crm65_permissions_matrix_does_not_grant_finance_to_support(): void
    {
        $this->assertContains('crm64.orders.view', Crm65Staff::permissionsForRole('support'));
        $this->assertNotContains('crm64.finance.view', Crm65Staff::permissionsForRole('support'));
        $this->assertNotContains('crm64.fiscal.view', Crm65Staff::permissionsForRole('support'));
        $this->assertContains('crm64.finance.view', Crm65Staff::permissionsForRole('finance'));
        $this->assertContains('crm64.fiscal.view', Crm65Staff::permissionsForRole('fiscal'));
    }

    public function test_cod_crm65_route_login_is_public_and_me_logout_protected(): void
    {
        $login = \Illuminate\Support\Facades\Route::getRoutes()->getByName('crm65.login');
        $me = \Illuminate\Support\Facades\Route::getRoutes()->getByName('crm65.me');
        $logout = \Illuminate\Support\Facades\Route::getRoutes()->getByName('crm65.logout');
        $this->assertNotNull($login);
        $this->assertNotNull($me);
        $this->assertNotNull($logout);
        $this->assertNotContains('auth:sanctum', $login->gatherMiddleware());
        $this->assertContains('auth:sanctum', $me->gatherMiddleware());
        $this->assertContains('auth:sanctum', $logout->gatherMiddleware());
    }
}
