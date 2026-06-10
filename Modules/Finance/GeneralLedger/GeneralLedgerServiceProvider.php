<?php

namespace Modules\Finance\GeneralLedger;

use Illuminate\Support\ServiceProvider;

class GeneralLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
