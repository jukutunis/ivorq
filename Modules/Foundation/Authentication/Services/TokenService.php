<?php

namespace Modules\Foundation\Authentication\Services;

use Laravel\Sanctum\NewAccessToken;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;

class TokenService
{
    public function create(User $user, string $deviceName = 'web'): string
    {
        $token = $user->createToken($deviceName);

        $this->recordSession($user, $token, $deviceName);

        return $token->plainTextToken;
    }

    public function revoke(User $user, int $tokenId): bool
    {
        return $user->tokens()->where('id', $tokenId)->delete() > 0;
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }

    private function recordSession(User $user, NewAccessToken $token, string $deviceName): void
    {
        UserSession::create([
            'user_id'       => $user->id,
            'property_id'   => app(\Shared\Services\CurrentPropertyService::class)->getPropertyId(),
            'token_id'      => $token->accessToken->id,
            'device_name'   => $deviceName,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'last_active_at' => now(),
        ]);
    }
}
