<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Repositories\RoleRepository;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class RoleService
{
    public function __construct(
        private RoleRepository $roleRepository,
        private ProtectedRoleGovernanceGuard $governanceGuard,
        private CurrentPropertyService $currentProperty,
    ) {}

    public function allForProperty(?string $propertyId = null): Collection
    {
        return $this->roleRepository->allForProperty($propertyId);
    }

    public function findForProperty(string $id, string $propertyId): Role
    {
        return $this->roleRepository->findForProperty($id, $propertyId);
    }

    public function create(string $name, ?string $propertyId = null): Role
    {
        $this->assertActorAuthority($propertyId, 'role.create');
        $this->governanceGuard->assertGenericNameAllowed($name);
        setPermissionsTeamId($propertyId);

        return $this->roleRepository->create([
            'name' => $name,
            'guard_name' => 'web',
            'property_id' => $propertyId,
        ]);
    }

    public function syncPermissionsForProperty(string $roleId, string $propertyId, array $permissions): Role
    {
        $this->assertActorAuthority($propertyId, 'role.edit');
        $role = $this->findForProperty($roleId, $propertyId);
        $canonicalNames = $this->governanceGuard->assertGenericMutationAllowed($role, $permissions);

        return $this->roleRepository->syncPermissions($role, $canonicalNames);
    }

    public function deleteForProperty(string $id, string $propertyId): bool
    {
        $this->assertActorAuthority($propertyId, 'role.delete');
        $this->governanceGuard->assertGenericMutationAllowed($this->findForProperty($id, $propertyId));

        return $this->roleRepository->deleteForProperty($id, $propertyId);
    }

    private function assertActorAuthority(?string $propertyId, string $permission): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $propertyId !== null
            && $this->currentProperty->getExplicitPropertyId() === $propertyId, 403);

        $authoritativeActor = User::query()->whereKey($actor->id)->where('is_active', true)->first();
        $property = Property::query()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->first();
        abort_unless($authoritativeActor && $property && $authoritativeActor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists(), 403);

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($propertyId);
        try {
            abort_unless($authoritativeActor->can($permission), 403);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
