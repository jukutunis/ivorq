<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Events\UserLoggedIn;
use Modules\Foundation\Authentication\Events\UserLoggedOut;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Repositories\UserRepository;

class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
        private TokenService $tokenService,
        private \Modules\Foundation\User\Repositories\UserSessionRepository $sessionRepository
    ) {}

    public function login(string $email, string $password, string $companyId, string $deviceName = 'web'): array
    {
        $user = $this->userRepository->findByEmailAndCompany($email, $companyId);

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        $token = $this->tokenService->create($user, $deviceName);

        event(new UserLoggedIn($user, request()));

        return [
            'user'  => $user,
            'token' => $token,
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
            }
            $user->currentAccessToken()->delete();
        }

        event(new UserLoggedOut($user));
    }

    public function logoutAllDevices(User $user): void
    {
        $user->tokens()->delete();
        $this->sessionRepository->revokeAllForUser($user->id);

        event(new UserLoggedOut($user));
    }
}
