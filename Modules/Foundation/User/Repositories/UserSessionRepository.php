<?php

namespace Modules\Foundation\User\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\User\Models\UserSession;

class UserSessionRepository
{
    public function activeForUser(string $userId): Collection
    {
        return UserSession::where('user_id', $userId)
            ->latest()
            ->get();
    }

    public function findByTokenId(int $tokenId): ?UserSession
    {
        return UserSession::where('token_id', $tokenId)->first();
    }

    public function updateLastActive(int $tokenId): void
    {
        UserSession::where('token_id', $tokenId)
            ->update(['last_active_at' => now()]);
    }

    public function revokeByTokenId(int $tokenId): bool
    {
        return UserSession::where('token_id', $tokenId)->delete() > 0;
    }

    public function revokeAllForUser(string $userId): void
    {
        UserSession::where('user_id', $userId)->delete();
    }
}
