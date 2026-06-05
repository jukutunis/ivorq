<?php

namespace Modules\Operations\PMS;

use Illuminate\Support\ServiceProvider;

class PMSServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
