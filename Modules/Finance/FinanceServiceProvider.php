<?php

namespace Modules\Finance;

use Illuminate\Support\ServiceProvider;
use Modules\Finance\Payables\PayablesServiceProvider;
use Modules\Finance\Banking\BankingServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    protected array $providers = [
        PayablesServiceProvider::class,
        BankingServiceProvider::class,
        \Modules\Finance\GeneralLedger\GeneralLedgerServiceProvider::class,
        \Modules\Finance\CostControl\CostControlServiceProvider::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void {}
}
