<?php

namespace Modules\Foundation\Authorization;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Authorization\Policies\RolePolicy;
use Spatie\Permission\Models\Role;

class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        Gate::policy(Role::class, RolePolicy::class);

        // Super admin bypasses all gates
        Gate::before(function ($user, $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });
    }
}
