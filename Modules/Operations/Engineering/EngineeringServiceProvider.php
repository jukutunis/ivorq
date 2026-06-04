<?php

namespace Modules\Operations\Engineering;

use Illuminate\Support\ServiceProvider;

class EngineeringServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
