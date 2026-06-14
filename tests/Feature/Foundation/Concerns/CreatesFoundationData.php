<?php

namespace Tests\Feature\Foundation\Concerns;

use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Database\Seeders\RoleSeeder;
use Modules\Operations\Engineering\Database\Seeders\EngineeringPermissionSeeder;
use Modules\Operations\Housekeeping\Database\Seeders\HousekeepingPermissionSeeder;
use Modules\Operations\PMS\Database\Seeders\PmsPermissionSeeder;
use Modules\Operations\Zoning\Database\Seeders\ZonePermissionSeeder;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\Position;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;

trait CreatesFoundationData
{
    private bool $permissionsSeeded = false;

    protected function seedPermissionsAndRoles(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(ZonePermissionSeeder::class);
        $this->seed(HousekeepingPermissionSeeder::class);
        $this->seed(EngineeringPermissionSeeder::class);
        $this->seed(PmsPermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->permissionsSeeded = true;
    }

    protected function createCompany(array $overrides = []): Company
    {
        static $sequence = 0;
        $sequence++;

        return Company::create(array_merge([
            'name'      => "Test Company {$sequence}",
            'slug'      => "test-company-{$sequence}",
            'is_active' => true,
        ], $overrides));
    }

    protected function createProperty(Company $company, array $overrides = []): Property
    {
        static $sequence = 0;
        $sequence++;

        return Property::create(array_merge([
            'company_id' => $company->id,
            'name'       => "Test Property {$sequence}",
            'slug'       => "test-property-{$sequence}",
            'code'       => "TP{$sequence}",
            'timezone'   => 'UTC',
            'currency'   => 'USD',
            'is_active'  => true,
        ], $overrides));
    }

    protected function createDepartment(Property $property, array $overrides = []): Department
    {
        static $sequence = 0;
        $sequence++;

        return Department::create(array_merge([
            'property_id' => $property->id,
            'name'        => "Department {$sequence}",
            'code'        => "D{$sequence}",
            'is_active'   => true,
        ], $overrides));
    }

    protected function createPosition(Property $property, Department $department, array $overrides = []): Position
    {
        static $sequence = 0;
        $sequence++;

        return Position::create(array_merge([
            'property_id'   => $property->id,
            'department_id' => $department->id,
            'name'          => "Position {$sequence}",
            'code'          => "P{$sequence}",
            'level'         => 2,
            'is_active'     => true,
        ], $overrides));
    }

    protected function createUser(Property $property, string $role = 'staff', array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        $this->seedPermissionsAndRoles();

        $user = User::create(array_merge([
            'name'              => "User {$sequence}",
            'email'             => "user{$sequence}@test.com",
            'password'          => 'password',
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $overrides));

        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status'     => 'active',
            'joined_at'  => now(),
        ]);

        setPermissionsTeamId($property->id);
        $user->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function createSuperAdmin(array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        $this->seedPermissionsAndRoles();

        $user = User::create(array_merge([
            'is_system_admin'   => true,
            'name'              => "Super Admin {$sequence}",
            'email'             => "superadmin{$sequence}@test.com",
            'password'          => 'password',
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $overrides));

        setPermissionsTeamId(null);
        $user->assignRole('super-admin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function createPropertyAdmin(Property $property, array $overrides = []): User
    {
        return $this->createUser($property, 'property-admin', $overrides);
    }

    /**
     * A user belonging to a second, independent property — used to test isolation.
     */
    protected function createOtherPropertyContext(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $user     = $this->createUser($property, 'property-admin');

        return compact('company', 'property', 'user');
    }
}
