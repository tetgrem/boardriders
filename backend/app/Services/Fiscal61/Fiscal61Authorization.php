<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class Fiscal61Authorization
{
    public const PREPARE = 'fiscal61.prepare';
    public const VIEW = 'fiscal61.view';
    public const SIMULATE = 'fiscal61.simulate';
    public const INCIDENT = 'fiscal61.incident';

    public function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            $user === null
            || !is_numeric($id)
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException(
                'Fiscal61 action not authorized'
            );
        }

        return (int) $id;
    }
}
