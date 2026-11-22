<?php

declare(strict_types=1);

namespace App\Services\Crm65;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class     Crm65Staff
{
    private const PERMISSIONS = [
        'crm64.orders.view',
        'crm64.finance.view',
        'crm64.fiscal.view',
        'crm64.notes.write',
        'crm64.reporting.view',
        'orders.returns.view',
        'cod.operations.ttn_lifecycle.view',
        'crm66.tasks.view',
        'crm66.tasks.manage',
        'crm68.staff.manage',
    ];

    private const ROLES = [
        'owner' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        'manager' => [0, 3, 4, 5, 6, 7, 8],
        'support' => [0, 3, 5, 6, 7, 8],
        'finance' => [0, 1, 3, 4, 5, 7],
        'fiscal' => [0, 2, 3, 4, 7],
        'viewer' => [0, 7],
    ];

    public static function permissionsForRole(string $role): array
    {
        if (!array_key_exists($role, self::ROLES)) {
            throw new DomainException('Invalid CRM staff role');
        }

        return array_map(
            static fn (int $index): string => self::PERMISSIONS[$index],
            self::ROLES[$role]
        );
    }

    public static function isManagedAbility(string $ability): bool
    {
        return in_array($ability, self::PERMISSIONS, true);
    }

    public function role(User $user): ?string
    {
        $row = DB::table('checkout_crm_staff65')
            ->where('user_id', $user->getKey())
            ->first(['active', 'role']);

        return $row !== null && (bool) $row->active
            ? (string) $row->role : null;
    }

    public function can(User $user, string $ability): bool
    {
        if (
            config('crm65.enabled') !== true
            || !self::isManagedAbility($ability)
            || !$user->currentAccessToken() instanceof PersonalAccessToken
            || !$user->tokenCan('crm65:access')
        ) {
            return false;
        }

        $role = $this->role($user);

        return $role !== null
            && in_array($ability, self::permissionsForRole($role), true);
    }

    public function identity(User $user): array
    {
        if (
            config('crm65.enabled') !== true
            || !$user->currentAccessToken() instanceof PersonalAccessToken
            || !$user->tokenCan('crm65:access')
        ) {
            throw new DomainException('CRM staff token required');
        }

        $role = $this->role($user);

        if ($role === null) {
            throw new DomainException('CRM staff access revoked');
        }

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $role,
            'abilities' => self::permissionsForRole($role),
        ];
    }
}
