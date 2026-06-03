<?php

namespace Modules\Foundation\Activity;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Activity\Models\ActivityLog;
use Modules\Foundation\Activity\Policies\ActivityLogPolicy;

class ActivityServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
    }
}
