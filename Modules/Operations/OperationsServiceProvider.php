<?php

namespace Modules\Operations;

use Illuminate\Support\ServiceProvider;
use Modules\Operations\Engineering\EngineeringServiceProvider;
use Modules\Operations\Housekeeping\HousekeepingServiceProvider;
use Modules\Operations\Zoning\ZoningServiceProvider;

class OperationsServiceProvider extends ServiceProvider
{
    protected array $providers = [
        // Order matters: upstream modules must boot before downstream
        ZoningServiceProvider::class,
        HousekeepingServiceProvider::class,
        EngineeringServiceProvider::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void {}
}
