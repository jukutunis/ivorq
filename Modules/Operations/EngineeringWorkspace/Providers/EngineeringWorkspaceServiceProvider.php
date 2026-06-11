<?php

namespace Modules\Operations\EngineeringWorkspace\Providers;

use Illuminate\Support\ServiceProvider;

class EngineeringWorkspaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
