<?php

namespace Modules\Operations\ContractorPTW\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ContractorPTWServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::prefix('api/v1/contractor-ptw')
            ->middleware(['api', 'auth:sanctum'])
            ->namespace('Modules\Operations\ContractorPTW\Controllers')
            ->group(__DIR__.'/../routes/api.php');
    }

    public function register(): void
    {
        //
    }
}
