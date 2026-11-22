<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Crm65\Crm65Staff;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class Crm65ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(static function ($user, string $ability): ?bool {
            // Preserve existing test fixtures and unrelated application gates.
            if (!Crm65Staff::isManagedAbility($ability) || !$user instanceof User) {
                return null;
            }

            return app(Crm65Staff::class)->can($user, $ability);
        });
    }
}
