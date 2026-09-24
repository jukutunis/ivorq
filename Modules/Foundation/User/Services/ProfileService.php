<?php

namespace Modules\Foundation\User\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Rules\PrivilegedOwnerPassword;
use Modules\Foundation\Authentication\Services\IdentitySecurityEventService;
use Modules\Foundation\Authentication\Services\SessionRevocationService;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;
use Modules\Foundation\User\Repositories\UserRepository;
use Modules\Foundation\User\Repositories\UserSessionRepository;

class ProfileService
{
    public function __construct(
        private UserRepository $userRepository,
        private UserSessionRepository $sessionRepository,
        private SessionRevocationService $revocation,
        private IdentitySecurityEventService $events,
    ) {}

    public function update(User $user, array $data): User
    {
        $payload = array_filter([
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'avatar' => $data['avatar'] ?? null,
        ]);

        return $this->userRepository->update($user->id, $payload);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        return DB::transaction(function () use ($user, $currentPassword, $newPassword): bool {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->password === null || ! Hash::check($currentPassword, $locked->password)) {
                return false;
            }

            $activation = OwnerActivation::query()
                ->where('user_id', $locked->id)
                ->where('status', OwnerActivationStatus::Active->value)
                ->first();
            if ($activation) {
                Validator::make(['password' => $newPassword], ['password' => [new PrivilegedOwnerPassword]])->validate();
                $locked->forceFill(['password' => $newPassword])->save();
                $this->events->record('PASSWORD_RESET', 'SUCCESS', [
                    'actor_user_id' => $locked->id,
                    'subject_user_id' => $locked->id,
                    'company_id' => $activation->company_id,
                    'property_id' => $activation->property_id,
                    'activation_id' => $activation->id,
                    'reason_code' => 'PASSWORD_CHANGE',
                ]);
                $this->revocation->revokeAll($locked, 'PASSWORD_CHANGE', [
                    'actor_user_id' => $locked->id,
                    'company_id' => $activation->company_id,
                    'property_id' => $activation->property_id,
                ]);
            } else {
                $locked->forceFill(['password' => $newPassword])->save();
                // Preserve the canonical non-owner behavior. SessionGuard verifies
                // the supplied password against the in-memory authenticated user,
                // so refresh that value after the locked update.
                $user->password = $locked->password;
                Auth::logoutOtherDevices($newPassword);

                if ($currentToken = $user->currentAccessToken()) {
                    $user->tokens()->where('id', '!=', $currentToken->id)->delete();
                    UserSession::query()
                        ->where('user_id', $user->id)
                        ->where('token_id', '!=', $currentToken->id)
                        ->delete();
                } else {
                    $user->tokens()->delete();
                    $this->sessionRepository->revokeAllForUser($user->id);
                }
            }

            return true;
        });
    }
}
