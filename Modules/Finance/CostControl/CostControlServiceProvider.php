<?php

namespace Modules\Finance\CostControl;

use Illuminate\Support\ServiceProvider;
use Modules\Finance\CostControl\Adapters\InventoryCostDeliveryModeAdapter;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;

class CostControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CostDeliveryModePort::class, InventoryCostDeliveryModeAdapter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
