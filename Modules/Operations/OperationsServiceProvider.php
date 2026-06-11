<?php

namespace Modules\Operations;

use Illuminate\Support\ServiceProvider;
use Modules\Operations\Engineering\EngineeringServiceProvider;
use Modules\Operations\Housekeeping\HousekeepingServiceProvider;
use Modules\Operations\Inventory\InventoryServiceProvider;
use Modules\Operations\PMS\PMSServiceProvider;
use Modules\Operations\Zoning\ZoningServiceProvider;
use Modules\Operations\Purchasing\PurchasingServiceProvider;

class OperationsServiceProvider extends ServiceProvider
{
    protected array $providers = [
        // Order matters: upstream modules must boot before downstream
        ZoningServiceProvider::class,
        HousekeepingServiceProvider::class,
        EngineeringServiceProvider::class,
        PMSServiceProvider::class,
        InventoryServiceProvider::class,
        PurchasingServiceProvider::class,
        \Modules\Operations\AssetManagement\AssetManagementServiceProvider::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void {}
}
