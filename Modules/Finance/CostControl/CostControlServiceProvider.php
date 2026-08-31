<?php

namespace Modules\Finance\CostControl;

use Illuminate\Support\ServiceProvider;
use Modules\Finance\CostControl\Adapters\InventoryCostDeliveryModeAdapter;
use Modules\Finance\CostControl\Adapters\InventorySynchronousCostValuationAdapter;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;

class CostControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CostDeliveryModePort::class, InventoryCostDeliveryModeAdapter::class);
        $this->app->bind(SynchronousCostValuationPort::class, InventorySynchronousCostValuationAdapter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
