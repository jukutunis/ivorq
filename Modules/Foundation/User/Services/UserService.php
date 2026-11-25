<?php

namespace Modules\Foundation\User\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Repositories\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    public function paginateForProperty(string $propertyId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->paginateForProperty($propertyId, $perPage);
    }

    public function findForProperty(string $id, string $propertyId): User
    {
        return $this->userRepository->findForProperty($id, $propertyId);
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
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        return $user;
    }

    public function updateForProperty(string $id, string $propertyId, array $data): User
    {
        $data = array_filter($data, fn ($value) => ! is_null($value) || $value === false);

        return $this->userRepository->updateForProperty($id, $propertyId, $data);
    }

    public function deleteForProperty(string $id, string $propertyId): bool
    {
        return $this->userRepository->deleteForProperty($id, $propertyId);
    }

    public function assignRole(string $userId, string $role, ?string $propertyId = null): void
    {
        $user = $this->find($userId);
        $teamId = $propertyId ?? $user->defaultProperty()?->id;

        setPermissionsTeamId($teamId);
        $user->assignRole($role);
    }
}
