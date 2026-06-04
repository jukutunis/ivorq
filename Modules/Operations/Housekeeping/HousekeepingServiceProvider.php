<?php

namespace Modules\Operations\Housekeeping;

use Illuminate\Support\ServiceProvider;

class HousekeepingServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Phase 13 — routes: $this->loadRoutesFrom(__DIR__ . '/routes/web.php')
    }
}
