<?php

namespace Tests\Postgres\Foundation\Authorization;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Audit\Repositories\AuditLogRepository;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Repositories\RoleRepository;
use Modules\Foundation\User\Repositories\UserRepository;
use Shared\Exceptions\NotFoundException;
use Tests\PostgresTestCase;

class SecP01TenantScopeAndTeamPivotTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_user_repository_hides_cross_property_and_inactive_memberships(): void
    {
        $company = CompanyFactory::new()->create();
        $propertyA = PropertyFactory::new()->create(['company_id' => $company->id]);
        $propertyB = PropertyFactory::new()->create(['company_id' => $company->id]);
        $userA = UserFactory::new()->withProperty($propertyA)->create();
        $userB = UserFactory::new()->withProperty($propertyB)->create();
        $repository = app(UserRepository::class);

        $this->assertSame([$userA->id], $repository->paginateForProperty($propertyA->id)->pluck('id')->all());
        $this->expectException(NotFoundException::class);
        $repository->findForProperty($userB->id, $propertyA->id);
    }

    public function test_inactive_membership_is_not_an_administration_scope(): void
    {
        $property = PropertyFactory::new()->create();
        $user = UserFactory::new()->withProperty($property, ['status' => 'inactive'])->create();

        $this->expectException(NotFoundException::class);
        app(UserRepository::class)->findForProperty($user->id, $property->id);
    }

    public function test_role_repository_hides_cross_property_and_global_catalog_roles(): void
    {
        $propertyA = PropertyFactory::new()->create();
        $propertyB = PropertyFactory::new()->create();
        $roleA = Role::query()->create(['name' => 'property-a-role', 'guard_name' => 'web', 'property_id' => $propertyA->id]);
        $roleB = Role::query()->create(['name' => 'property-b-role', 'guard_name' => 'web', 'property_id' => $propertyB->id]);
        $globalRole = Role::query()->create(['name' => 'source-controlled-role', 'guard_name' => 'web', 'property_id' => null]);
        $repository = app(RoleRepository::class);

        $this->assertSame($roleA->id, $repository->findForProperty($roleA->id, $propertyA->id)->id);

        foreach ([$roleB, $globalRole] as $inaccessible) {
            try {
                $repository->findForProperty($inaccessible->id, $propertyA->id);
                $this->fail('An out-of-scope role was returned.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_audit_repository_scopes_list_detail_and_model_history(): void
    {
        $propertyA = PropertyFactory::new()->create();
        $propertyB = PropertyFactory::new()->create();
        $auditableId = (string) Str::ulid();
        $logA = AuditLog::record([
            'property_id' => $propertyA->id,
            'event' => 'updated',
            'auditable_type' => 'SecP01Subject',
            'auditable_id' => $auditableId,
        ]);
        $logB = AuditLog::record([
            'property_id' => $propertyB->id,
            'event' => 'updated',
            'auditable_type' => 'SecP01Subject',
            'auditable_id' => $auditableId,
        ]);
        $repository = app(AuditLogRepository::class);

        $this->assertSame([$logA->id], $repository->paginateForProperty($propertyA->id)->pluck('id')->all());
        $this->assertSame([$logA->id], $repository->forModelInProperty('SecP01Subject', $auditableId, $propertyA->id)->pluck('id')->all());
        $this->expectException(NotFoundException::class);
        $repository->findForProperty($logB->id, $propertyA->id);
    }

    public function test_same_team_authority_can_coexist_across_properties_but_not_duplicate_within_one(): void
    {
        $propertyA = PropertyFactory::new()->create();
        $propertyB = PropertyFactory::new()->create();
        $user = UserFactory::new()->withProperty($propertyA)->create();
        $role = Role::query()->create(['name' => 'shared-team-role', 'guard_name' => 'web', 'property_id' => null]);
        $base = [
            'role_id' => $role->id,
            'model_id' => $user->id,
            'model_type' => $user->getMorphClass(),
        ];

        DB::table('model_has_roles')->insert($base + ['property_id' => $propertyA->id]);
        DB::table('model_has_roles')->insert($base + ['property_id' => $propertyB->id]);
        DB::table('model_has_roles')->insert($base + ['property_id' => null]);

        $this->assertSame(3, DB::table('model_has_roles')->where($base)->count());

        $this->expectException(QueryException::class);
        DB::table('model_has_roles')->insert($base + ['property_id' => $propertyA->id]);
    }

    public function test_same_direct_permission_can_coexist_across_properties_but_not_duplicate_within_one(): void
    {
        $propertyA = PropertyFactory::new()->create();
        $propertyB = PropertyFactory::new()->create();
        $user = UserFactory::new()->withProperty($propertyA)->create();
        $permission = Permission::query()->create(['name' => 'shared.direct.permission', 'guard_name' => 'web']);
        $base = [
            'permission_id' => $permission->id,
            'model_id' => $user->id,
            'model_type' => $user->getMorphClass(),
        ];

        DB::table('model_has_permissions')->insert($base + ['property_id' => $propertyA->id]);
        DB::table('model_has_permissions')->insert($base + ['property_id' => $propertyB->id]);
        DB::table('model_has_permissions')->insert($base + ['property_id' => null]);
        $this->assertSame(3, DB::table('model_has_permissions')->where($base)->count());

        $this->expectException(QueryException::class);
        DB::table('model_has_permissions')->insert($base + ['property_id' => $propertyA->id]);
    }
}
