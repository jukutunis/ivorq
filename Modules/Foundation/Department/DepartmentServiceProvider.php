<?php

namespace Modules\Foundation\Department;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Policies\DepartmentPolicy;

class DepartmentServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        Gate::policy(Department::class, DepartmentPolicy::class);
    }
}
