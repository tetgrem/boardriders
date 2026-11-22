<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class Crm64Access
{
    public const VIEW = 'crm64.orders.view';
    public const FINANCE = 'crm64.finance.view';
    public const FISCAL = 'crm64.fiscal.view';
    public const NOTES = 'crm64.notes.write';
    public const REPORT = 'crm64.reporting.view';

    public function enabled(): void
    {
        if (config('crm64.enabled') !== true) {
            throw new DomainException('CRM64 is disabled');
        }
    }

    public function actor(string $ability): int
    {
        $this->enabled();
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ($user === null || !is_numeric($id) || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('CRM64 access denied');
        }
        return (int) $id;
    }

    public function has(string $ability): bool
    {
        $user = Auth::user();
        return $user !== null && Gate::forUser($user)->allows($ability);
    }
}
