<?php

namespace Modules\Finance\Payables;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Finance\Payables\Policies\VendorInvoicePolicy;
use Modules\Finance\Payables\Models\VendorInvoice;
use Illuminate\Support\Facades\Gate;

class PayablesServiceProvider extends ServiceProvider
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
        Gate::policy(VendorInvoice::class, VendorInvoicePolicy::class);
    }

    protected function registerRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'permission.team'])
            ->group(__DIR__ . '/routes/api.php');
    }
}
