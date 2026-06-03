<?php

namespace Modules\Foundation\Property;

use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Property\Contracts\PropertyRepositoryInterface;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Policies\CompanyPolicy;
use Modules\Foundation\Property\Policies\PropertyPolicy;
use Modules\Foundation\Property\Repositories\PropertyRepository;

class PropertyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PropertyRepositoryInterface::class, PropertyRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

        $this->registerPolicies();
    }

    private function registerPolicies(): void
    {
        \Illuminate\Support\Facades\Gate::policy(Property::class, PropertyPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(Company::class, CompanyPolicy::class);
    }
}
