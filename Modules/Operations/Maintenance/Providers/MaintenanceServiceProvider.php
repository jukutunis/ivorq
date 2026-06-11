<?php

namespace Modules\Operations\Maintenance\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Modules\Operations\Maintenance\Models\MaintenancePlan;
use Modules\Operations\Maintenance\Models\MaintenanceExecution;
use Modules\Operations\Maintenance\Policies\MaintenancePlanPolicy;
use Modules\Operations\Maintenance\Policies\MaintenanceExecutionPolicy;
use Modules\Operations\Maintenance\Events\MaintenanceExecutionCompleted;
use Modules\Operations\Maintenance\Listeners\LogMaintenanceHistory;

class MaintenanceServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');

        // Policies
        Gate::policy(MaintenancePlan::class, MaintenancePlanPolicy::class);
        Gate::policy(MaintenanceExecution::class, MaintenanceExecutionPolicy::class);

        // Events
        Event::listen(MaintenanceExecutionCompleted::class, [LogMaintenanceHistory::class, 'handle']);

        // Routes
        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/api.php');
    }
}
