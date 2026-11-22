<?php

declare(strict_types=1);

namespace App\Services\Fiscal63;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class Fiscal63Access
{
    public const MONITOR = 'fiscal63.monitor';
    public const ACK = 'fiscal63.acknowledge';
    public const NOTE = 'fiscal63.note';
    public const RECOVER = 'fiscal63.recover';
    public const POLL = 'fiscal63.poll';
    public const CLOSE = 'fiscal63.close';
    public const VIEW = 'fiscal63.view';

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
                'Fiscal63 access denied'
            );
        }

        return (int) $id;
    }
}
