<?php

namespace Modules\Foundation\Audit;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Audit\Observers\AuditObserver;
use Modules\Foundation\Audit\Policies\AuditLogPolicy;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * Models that will be observed for audit trail.
     * Register every business model here as modules are built.
     */
    private array $auditableModels = [
        \Modules\Foundation\Property\Models\Company::class,
        \Modules\Foundation\Property\Models\Property::class,
        \Modules\Foundation\Property\Models\PropertySetting::class,
        \Modules\Foundation\Department\Models\Department::class,
        \Modules\Foundation\Department\Models\Position::class,
        \Modules\Foundation\User\Models\User::class,
    ];

    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        $this->registerObservers();
    }

    private function registerObservers(): void
    {
        foreach ($this->auditableModels as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
