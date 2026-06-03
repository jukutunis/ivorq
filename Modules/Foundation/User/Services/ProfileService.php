<?php

namespace Modules\Foundation\User\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Repositories\UserRepository;

class ProfileService
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    public function update(User $user, array $data): User
    {
        $payload = array_filter([
            'name'   => $data['name']  ?? null,
            'phone'  => $data['phone'] ?? null,
            'avatar' => $data['avatar'] ?? null,
        ]);

        return $this->userRepository->update($user->id, $payload);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $this->userRepository->update($user->id, ['password' => $newPassword]);

        return true;
    }
}
