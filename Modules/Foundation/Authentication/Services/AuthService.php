<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Events\UserLoggedIn;
use Modules\Foundation\Authentication\Events\UserLoggedOut;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Repositories\UserRepository;
use Modules\Foundation\User\Repositories\UserSessionRepository;

class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
        private UserSessionRepository $sessionRepository,
        private IdentityChallengeService $challenges,
        private IdentitySecurityEventService $events,
        private SessionRevocationService $revocation,
    ) {}

    public function login(string $email, string $password, string $companyId, string $channel = 'web', ?string $guestSessionId = null): array
    {
        $email = mb_strtolower(trim($email));
        $user = $this->userRepository->findByEmailAndCompany($email, $companyId);

        if (! $user || $user->password === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        $properties = $user->properties()
            ->where('company_id', $companyId)
            ->where('properties.is_active', true)
            ->wherePivot('status', 'active')
            ->get();
        if ($properties->isEmpty()) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $activation = OwnerActivation::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();
        if ($activation) {
            if ($activation->status !== OwnerActivationStatus::Active) {
                throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
            }

            $property = $properties->firstWhere('id', $activation->property_id);
            if (! $property || ! $property->pivot->is_default) {
                throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
            }

            $challenge = DB::transaction(function () use ($user, $activation, $channel, $guestSessionId): string {
                $this->events->record('LOGIN_PASSWORD_ACCEPTED', 'SUCCESS', [
                    'subject_user_id' => $user->id,
                    'company_id' => $activation->company_id,
                    'property_id' => $activation->property_id,
                    'activation_id' => $activation->id,
                ]);

                return $this->challenges->issueLogin($user, $activation, $channel, $guestSessionId);
            });

            return [
                'mfa_required' => true,
                'challenge' => $challenge,
                'property_id' => $activation->property_id,
            ];
        }

        event(new UserLoggedIn($user, request()));

        return [
            'user' => $user,
            'properties' => $properties,
            'mfa_required' => false,
        ];
    }

    public function logout(User $user, ?int $tokenId = null): void
    {
        if ($tokenId) {
            $user->tokens()->where('id', $tokenId)->delete();
            $this->sessionRepository->revokeByTokenId($tokenId);
        } else {
            if ($token = $user->currentAccessToken()) {
                $this->sessionRepository->revokeByTokenId($token->id);
                $token->delete();
            }
        }

        event(new UserLoggedOut($user));
    }

    public function logoutAllDevices(User $user): void
    {
        $activation = OwnerActivation::query()->where('user_id', $user->id)->where('status', OwnerActivationStatus::Active->value)->first();
        if ($activation) {
            $this->revocation->revokeAll($user, 'LOGOUT_ALL', [
                'actor_user_id' => $user->id,
                'company_id' => $activation->company_id,
                'property_id' => $activation->property_id,
            ]);
        } else {
            $user->tokens()->delete();
            $this->sessionRepository->revokeAllForUser($user->id);
        }

        event(new UserLoggedOut($user));
    }
}
