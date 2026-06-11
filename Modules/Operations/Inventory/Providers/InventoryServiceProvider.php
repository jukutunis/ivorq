<?php

namespace Modules\Operations\Inventory\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::prefix('api/v1/inventory')
            ->middleware(['api', 'auth:sanctum'])
            ->namespace('Modules\Operations\Inventory\Controllers')
            ->group(__DIR__.'/../routes/api.php');
    }

    public function register(): void
    {
        //
    }
}
