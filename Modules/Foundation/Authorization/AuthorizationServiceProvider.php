<?php

namespace Modules\Foundation\Authorization;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Infrastructure\FirstTrustAuthority\UnconfiguredFirstTrustAuthority;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Policies\RolePolicy;

class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FirstTrustAuthority::class, UnconfiguredFirstTrustAuthority::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        Gate::policy(Role::class, RolePolicy::class);

        // Super admin bypasses all gates
        Gate::before(function ($user, $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });
    }
}
