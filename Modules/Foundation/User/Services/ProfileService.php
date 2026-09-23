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
        if ($user->password === null || ! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $activation = OwnerActivation::query()->where('user_id', $user->id)->where('status', OwnerActivationStatus::Active->value)->first();
        if ($activation) {
            Validator::make(['password' => $newPassword], ['password' => [new PrivilegedOwnerPassword]])->validate();
            DB::transaction(function () use ($user, $newPassword, $activation): void {
                $this->userRepository->update($user->id, ['password' => $newPassword]);
                $this->events->record('PASSWORD_RESET', 'SUCCESS', [
                    'actor_user_id' => $user->id,
                    'subject_user_id' => $user->id,
                    'company_id' => $activation->company_id,
                    'property_id' => $activation->property_id,
                    'activation_id' => $activation->id,
                    'reason_code' => 'PASSWORD_CHANGE',
                ]);
                $this->revocation->revokeAll($user, 'PASSWORD_CHANGE', [
                    'actor_user_id' => $user->id,
                    'company_id' => $activation->company_id,
                    'property_id' => $activation->property_id,
                ]);
            });
        } else {
            $this->userRepository->update($user->id, ['password' => $newPassword]);
            // Preserve the canonical non-owner behavior. SessionGuard verifies
            // the supplied password against the in-memory authenticated user,
            // so refresh that value after the repository update.
            $user->password = Hash::make($newPassword);
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
    }
}
