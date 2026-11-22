<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class Fiscal62Access
{
    public const PROFILE = 'fiscal62.profile';
    public const PREPARE = 'fiscal62.prepare';
    public const SEND = 'fiscal62.send';
    public const POLL = 'fiscal62.poll';
    public const VIEW = 'fiscal62.view';

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
                'Fiscal62CheckboxClient.php access denied'
            );
        }

        return (int) $id;
    }
}
