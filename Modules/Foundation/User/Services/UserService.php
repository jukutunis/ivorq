<?php

namespace Modules\Foundation\User\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Repositories\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private \Modules\Foundation\User\Repositories\UserSessionRepository $sessionRepository
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->paginate($perPage);
    }

    public function find(string $id): User
    {
        return $this->userRepository->find($id);
    }

    public function create(array $data): User
    {
        $propertyId = $data['property_id'] ?? null;
        unset($data['property_id']);

        $user = $this->userRepository->create($data);

        if ($propertyId) {
            $user->properties()->attach($propertyId, [
                'is_default' => true,
                'status'     => 'active',
                'joined_at'  => now(),
            ]);
        }

        return $user;
    }

    public function update(string $id, array $data): User
    {
        $data = array_filter($data, fn($value) => !is_null($value) || $value === false);

        $user = $this->userRepository->update($id, $data);

        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            $user->tokens()->delete();
            $this->sessionRepository->revokeAllForUser($id);
            event(new \Modules\Foundation\Authentication\Events\UserLoggedOut($user));
        }

        return $user;
    }

    public function delete(string $id): bool
    {
        return $this->userRepository->delete($id);
    }

    public function assignRole(string $userId, string $role, ?string $propertyId = null): void
    {
        $user = $this->find($userId);
        $teamId = $propertyId ?? $user->defaultProperty()?->id;

        setPermissionsTeamId($teamId);
        $user->assignRole($role);
    }
}
