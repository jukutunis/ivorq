<?php

namespace Modules\Foundation\Authentication;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Modules\Foundation\Authentication\Events\UserLoggedIn;
use Modules\Foundation\Authentication\Events\UserLoggedOut;
use Modules\Foundation\Authentication\Listeners\CleanupLogoutSession;
use Modules\Foundation\Authentication\Listeners\RecordLoginSession;
use Modules\Foundation\Authentication\Services\AuthenticatedSecurityService;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;

class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        $this->registerEventListeners();

        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid): bool {
            if (! $isValid || ! ($accessToken->tokenable instanceof User)) {
                return false;
            }

            $valid = app(AuthenticatedSecurityService::class)->valid($accessToken->tokenable, request(), $accessToken);
            if (! $valid) {
                UserSession::query()->where('token_id', $accessToken->id)->delete();
                $accessToken->delete();
            }

            return $valid;
        });
    }

    private function registerEventListeners(): void
    {
        Event::listen(UserLoggedIn::class, RecordLoginSession::class);
        Event::listen(UserLoggedOut::class, CleanupLogoutSession::class);
    }
}
