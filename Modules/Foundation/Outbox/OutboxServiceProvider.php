<?php

namespace Modules\Foundation\Outbox;

use Illuminate\Support\ServiceProvider;

class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings needed for Slice 0
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
