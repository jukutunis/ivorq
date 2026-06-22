<?php

namespace Modules\Finance\CostControl;

use Illuminate\Support\ServiceProvider;

class CostControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
