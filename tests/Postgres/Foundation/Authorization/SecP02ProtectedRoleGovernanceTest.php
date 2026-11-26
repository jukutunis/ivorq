<?php

namespace Tests\Postgres\Foundation\Authorization;

use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Services\RoleService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Services\UserService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\PostgresTestCase;

class SecP02ProtectedRoleGovernanceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_generic_role_service_preserves_ordinary_permission_edit_but_denies_fx_authority(): void
    {
        $property = PropertyFactory::new()->create();
        $this->actingRoleManager($property, ['role.create', 'role.edit']);
        $ordinary = Permission::query()->firstOrCreate(['name' => 'task.view', 'guard_name' => 'web']);
        $protected = Permission::query()->firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        $roles = app(RoleService::class);
        $role = $roles->create('local-read-role', $property->id);

        $this->assertSame([$ordinary->name], $roles->syncPermissionsForProperty($role->id, $property->id, [$ordinary->name])->permissions->pluck('name')->all());

        try {
            $roles->syncPermissionsForProperty($role->id, $property->id, [$protected->name]);
            $this->fail('Generic role editing granted governed FX authority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('permissions', $exception->errors());
        }

        $this->assertSame([$ordinary->name], $role->fresh('permissions')->permissions->pluck('name')->all());
    }

    public function test_protected_permission_ulid_and_mixed_request_cannot_replace_existing_pivots(): void
    {
        $property = PropertyFactory::new()->create();
        $this->actingRoleManager($property, ['role.create', 'role.edit']);
        $ordinary = Permission::query()->firstOrCreate(['name' => 'task.view', 'guard_name' => 'web']);
        $protected = Permission::query()->firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        $roles = app(RoleService::class);
        $role = $roles->create('local-read-role', $property->id);
        $roles->syncPermissionsForProperty($role->id, $property->id, [$ordinary->name]);
        $before = $role->fresh('permissions')->permissions->pluck('id')->all();

        foreach ([[$protected->id], [$ordinary->name, $protected->id]] as $requested) {
            try {
                $roles->syncPermissionsForProperty($role->id, $property->id, $requested);
                $this->fail('A protected permission identifier bypassed generic role governance.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('permissions', $exception->errors());
            }

            $this->assertSame($before, $role->fresh('permissions')->permissions->pluck('id')->all());
            $this->assertDatabaseMissing('role_has_permissions', [
                'role_id' => $role->id,
                'permission_id' => $protected->id,
            ]);
        }
    }

    public function test_unsupported_permission_representations_and_guard_mappings_fail_before_sync(): void
    {
        $property = PropertyFactory::new()->create();
        $this->actingRoleManager($property, ['role.create', 'role.edit']);
        $ordinary = Permission::query()->firstOrCreate(['name' => 'task.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'api-only.view', 'guard_name' => 'api']);
        $roles = app(RoleService::class);
        $role = $roles->create('local-read-role', $property->id);
        $roles->syncPermissionsForProperty($role->id, $property->id, [$ordinary->name]);
        $before = $role->fresh('permissions')->permissions->pluck('id')->all();

        foreach ([
            [1],
            [$ordinary],
            [[$ordinary->name]],
            ['missing.permission'],
            ['api-only.view'],
            [' '],
            ['permission' => $ordinary->name],
        ] as $requested) {
            try {
                $roles->syncPermissionsForProperty($role->id, $property->id, $requested);
                $this->fail('An unsupported permission representation was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('permissions', $exception->errors());
            }

            $this->assertSame($before, $role->fresh('permissions')->permissions->pluck('id')->all());
        }

        $apiRole = Role::query()->create(['name' => 'local-api-role', 'guard_name' => 'api', 'property_id' => $property->id]);
        try {
            $roles->syncPermissionsForProperty($apiRole->id, $property->id, ['api-only.view']);
            $this->fail('Generic role administration accepted an invalid role guard.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('permissions', $exception->errors());
        }
        $this->assertCount(0, $apiRole->fresh('permissions')->permissions);
    }

    public function test_canonical_ordinary_permission_names_and_empty_list_remain_supported(): void
    {
        $property = PropertyFactory::new()->create();
        $this->actingRoleManager($property, ['role.create', 'role.edit']);
        $ordinary = Permission::query()->firstOrCreate(['name' => 'task.view', 'guard_name' => 'web']);
        $role = app(RoleService::class)->create('local-read-role', $property->id);

        $granted = app(RoleService::class)->syncPermissionsForProperty($role->id, $property->id, [$ordinary->name, $ordinary->name]);
        $this->assertSame([$ordinary->id], $granted->permissions->pluck('id')->all());

        $cleared = app(RoleService::class)->syncPermissionsForProperty($role->id, $property->id, []);
        $this->assertCount(0, $cleared->permissions);
        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $role->id, 'permission_id' => $ordinary->id]);
    }

    public function test_generic_paths_cannot_recreate_or_edit_protected_roles(): void
    {
        $property = PropertyFactory::new()->create();
        $this->actingRoleManager($property, ['role.create', 'role.edit', 'role.delete']);
        $roles = app(RoleService::class);

        try {
            $roles->create('finance-manager', $property->id);
            $this->fail('A source-controlled FX role name was recreated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
        $this->assertDatabaseMissing('roles', ['name' => 'finance-manager', 'property_id' => $property->id]);

        $protectedRole = Role::query()->create(['name' => 'installation-owner', 'guard_name' => 'web', 'property_id' => $property->id]);
        foreach ([
            fn () => $roles->syncPermissionsForProperty($protectedRole->id, $property->id, []),
            fn () => $roles->deleteForProperty($protectedRole->id, $property->id),
        ] as $command) {
            try {
                $command();
                $this->fail('Generic role mutation changed a protected role.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('permissions', $exception->errors());
            }
        }
        $this->assertDatabaseHas('roles', ['id' => $protectedRole->id, 'name' => 'installation-owner']);

        $fxPermission = Permission::query()->firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        $legacyCustom = Role::query()->create(['name' => 'legacy-custom-fx', 'guard_name' => 'web', 'property_id' => $property->id]);
        $legacyCustom->givePermissionTo($fxPermission);
        foreach ([
            fn () => $roles->syncPermissionsForProperty($legacyCustom->id, $property->id, []),
            fn () => $roles->deleteForProperty($legacyCustom->id, $property->id),
        ] as $command) {
            try {
                $command();
                $this->fail('Generic mutation changed a role already holding governed FX authority.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('permissions', $exception->errors());
            }
        }
        $this->assertDatabaseHas('roles', ['id' => $legacyCustom->id]);
    }

    public function test_generic_user_role_helper_fails_closed_without_actor_or_approval(): void
    {
        $property = PropertyFactory::new()->create();
        $target = UserFactory::new()->withProperty($property)->create();

        try {
            app(UserService::class)->assignRole($target->id, 'finance-manager', $property->id);
            $this->fail('Ungoverned role assignment was accepted.');
        } catch (\DomainException $exception) {
            $this->assertSame('ROLE_ASSIGNMENT_REQUIRES_GOVERNED_WORKFLOW', $exception->getMessage());
        }
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $target->id, 'property_id' => $property->id]);
    }

    public function test_http_role_creation_rolls_back_when_fx_permission_is_rejected(): void
    {
        $property = PropertyFactory::new()->create();
        $actor = UserFactory::new()->withProperty($property)->create();
        $createPermission = Permission::query()->firstOrCreate(['name' => 'role.create', 'guard_name' => 'web']);
        $editPermission = Permission::query()->firstOrCreate(['name' => 'role.edit', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'finance.fx-adjustment.view', 'guard_name' => 'web']);
        setPermissionsTeamId($property->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo([$createPermission, $editPermission]);
        $login = $this->withSession(['login.tenant_id' => $property->company_id])->post('/login', [
            'email' => $actor->email,
            'password' => 'password',
        ]);
        $login->assertRedirect('/frontdesk');
        $cookie = $login->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie(config('session.cookie'), $cookie->getValue());

        $this->post('/roles', [
            'name' => 'local-fx-bypass',
            'permissions' => ['finance.fx-adjustment.view'],
        ])->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('roles', ['name' => 'local-fx-bypass', 'property_id' => $property->id]);
    }

    public function test_direct_role_service_denies_actor_outside_canonical_property(): void
    {
        $property = PropertyFactory::new()->create();
        $otherProperty = PropertyFactory::new()->create();
        $this->actingRoleManager($otherProperty, ['role.create']);
        app(CurrentPropertyService::class)->setPropertyId($property->id);

        $this->expectException(HttpException::class);
        app(RoleService::class)->create('cross-property-role', $property->id);
    }

    private function actingRoleManager(Property $property, array $permissions): User
    {
        $actor = UserFactory::new()->withProperty($property)->create();
        foreach ($permissions as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        setPermissionsTeamId($property->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo($permissions);
        $this->actingAs($actor, 'web');
        app(CurrentPropertyService::class)->setPropertyId($property->id);

        return $actor;
    }
}
