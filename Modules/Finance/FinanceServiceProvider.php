<?php

namespace Modules\Finance;

use Illuminate\Support\ServiceProvider;
use Modules\Finance\Payables\PayablesServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    protected array $providers = [
        PayablesServiceProvider::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void {}
}
