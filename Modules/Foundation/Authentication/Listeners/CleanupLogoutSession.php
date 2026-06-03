<?php

namespace Modules\Foundation\Authentication\Listeners;

use Modules\Foundation\Authentication\Events\UserLoggedOut;
use Modules\Foundation\User\Repositories\UserSessionRepository;

class CleanupLogoutSession
{
    public function __construct(
        private UserSessionRepository $sessionRepository
    ) {}

    public function handle(UserLoggedOut $event): void
    {
        $token = $event->user->currentAccessToken();

        if ($token && $token->id) {
            $this->sessionRepository->revokeByTokenId($token->id);
        }
    }
}
