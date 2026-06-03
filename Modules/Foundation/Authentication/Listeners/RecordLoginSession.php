<?php

namespace Modules\Foundation\Authentication\Listeners;

use Modules\Foundation\Authentication\Events\UserLoggedIn;
use Modules\Foundation\User\Models\UserSession;

class RecordLoginSession
{
    public function handle(UserLoggedIn $event): void
    {
        // Session is recorded by TokenService at token creation time.
        // This listener handles any supplementary work on login:
        // e.g. recording failed attempt resets, last-login timestamps.

        $event->user->sessions()
            ->where('ip_address', $event->request->ip())
            ->update(['last_active_at' => now()]);
    }
}
