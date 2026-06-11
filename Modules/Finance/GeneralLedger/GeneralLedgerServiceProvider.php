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
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
        
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Finance\GeneralLedger\Console\BackfillCoaCommand::class,
            ]);
        }
    }
}
