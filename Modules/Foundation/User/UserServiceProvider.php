<?php

namespace Modules\Foundation\User;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Policies\UserPolicy;

class UserServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        Gate::policy(User::class, UserPolicy::class);
    }
}
