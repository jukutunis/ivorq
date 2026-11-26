<?php

namespace Modules\Foundation\User\Services;

use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Repositories\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private UserAdministrationGuard $administrationGuard,
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
        $forbidden = [
            'password', 'password_confirmation', 'is_active', 'is_system_admin', 'auth_epoch', 'remember_token', 'deleted_at',
            'property_id', 'properties', 'company_id', 'roles', 'permissions', 'sessions', 'session_state',
        ];
        if (array_intersect(array_keys($data), $forbidden) !== []) {
            throw new DomainException('GENERIC_USER_SECURITY_STATE_MUTATION_NOT_ALLOWED');
        }

        $target = $this->userRepository->findForProperty($id, $propertyId);
        $property = Property::query()->whereKey($propertyId)->firstOrFail();
        $this->administrationGuard->assertOrdinaryMutationAllowed($target, $property);

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
