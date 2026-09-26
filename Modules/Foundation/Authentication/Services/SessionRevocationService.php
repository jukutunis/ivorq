<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\User\Models\User;

class SessionRevocationService
{
    public function __construct(private IdentitySecurityEventService $events) {}

    public function revokeAll(User $user, string $reasonCode, array $context = []): User
    {
        return DB::transaction(function () use ($user, $reasonCode, $context): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'auth_epoch' => $locked->auth_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();

            $locked->tokens()->delete();
            DB::table('user_sessions')->where('user_id', $locked->id)->delete();
            DB::table('sessions')->where('user_id', $locked->id)->delete();

            $this->events->record('SESSIONS_REVOKED', 'SUCCESS', [
                'actor_user_id' => $context['actor_user_id'] ?? $locked->id,
                'subject_user_id' => $locked->id,
                'company_id' => $context['company_id'] ?? null,
                'property_id' => $context['property_id'] ?? null,
                'reason_code' => $reasonCode,
            ], [
                'auth_epoch' => (int) $locked->auth_epoch,
            ]);

            return $locked->fresh();
        });
    }
}
