<?php

namespace Modules\Foundation\Authentication;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Authentication\Events\UserLoggedIn;
use Modules\Foundation\Authentication\Events\UserLoggedOut;
use Modules\Foundation\Authentication\Listeners\CleanupLogoutSession;
use Modules\Foundation\Authentication\Listeners\RecordLoginSession;

class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');

        $this->registerEventListeners();
    }

    private function registerEventListeners(): void
    {
        Event::listen(UserLoggedIn::class, RecordLoginSession::class);
        Event::listen(UserLoggedOut::class, CleanupLogoutSession::class);
    }
}
