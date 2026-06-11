<?php

namespace Modules\Operations\AssetManagement;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\Policies\AssetPolicy;

class AssetManagementServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // Policies
        Gate::policy(Asset::class, AssetPolicy::class);

        // Routes
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
    }
}
