<?php

namespace Modules\Finance\FxReference\Providers;

use Illuminate\Support\ServiceProvider;

class FxReferenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
    }
}
