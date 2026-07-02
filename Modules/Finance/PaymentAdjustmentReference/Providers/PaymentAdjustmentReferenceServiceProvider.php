<?php

namespace Modules\Finance\PaymentAdjustmentReference\Providers;

use Illuminate\Support\ServiceProvider;

class PaymentAdjustmentReferenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
    }
}
