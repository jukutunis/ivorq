<?php

namespace Modules\Finance\Banking;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Policies\BankAccountPolicy;

class BankingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerRoutes();
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    protected function registerPolicies(): void
    {
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(\Modules\Finance\Banking\Models\BankStatement::class, \Modules\Finance\Banking\Policies\BankStatementPolicy::class);
    }

    protected function registerRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'permission.team'])
            ->group(__DIR__ . '/routes/api.php');
    }
}
