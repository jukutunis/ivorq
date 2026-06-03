<?php

namespace Modules\Foundation\User\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Repositories\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $userRepository
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
        return $this->userRepository->create($data);
    }

    public function update(string $id, array $data): User
    {
        $data = array_filter($data, fn($value) => !is_null($value) || $value === false);

        return $this->userRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->userRepository->delete($id);
    }

    public function assignRole(string $userId, string $role, ?string $propertyId = null): void
    {
        $user = $this->find($userId);
        $teamId = $propertyId ?? $user->property_id;

        setPermissionsTeamId($teamId);
        $user->assignRole($role);
    }
}
