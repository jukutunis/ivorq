<?php

namespace Tests\Postgres\Foundation\User;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authentication\Models\IdentitySecurityEvent;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\User\Models\UserSession;
use Modules\Foundation\User\Services\UserLifecycleService;
use Tests\PostgresTestCase;

class SecP01UserLifecycleTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_deactivate_is_locked_revoking_evidenced_and_replay_safe(): void
    {
        [$actor, $target, $property] = $this->usersInOneProperty();
        UserSession::query()->create([
            'user_id' => $target->id,
            'property_id' => $property->id,
            'channel' => 'web',
            'auth_epoch' => 0,
            'web_session_digest' => hash('sha256', 'sec-p01-deactivate'),
        ]);

        $result = app(UserLifecycleService::class)->deactivate($actor, $target->id, $property->id);

        $this->assertFalse($result->is_active);
        $this->assertSame(1, $result->auth_epoch);
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $target->id]);
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'USER_DEACTIVATED')->count());
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'SESSIONS_REVOKED')->where('reason_code', 'USER_DEACTIVATED')->count());

        $replayed = app(UserLifecycleService::class)->deactivate($actor, $target->id, $property->id);
        $this->assertSame(1, $replayed->auth_epoch);
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'USER_DEACTIVATED')->count());
    }

    public function test_reactivate_requires_exactly_one_valid_default_membership(): void
    {
        [$actor, $target, $property] = $this->usersInOneProperty();
        $target->forceFill(['is_active' => false])->save();
        DB::table('property_user')->where(['user_id' => $target->id, 'property_id' => $property->id])->update(['is_default' => false]);

        try {
            app(UserLifecycleService::class)->activate($actor, $target->id, $property->id);
            $this->fail('Activation without a valid default membership was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('ACTIVE_USER_REQUIRES_ONE_VALID_DEFAULT_MEMBERSHIP', $exception->getMessage());
        }

        DB::table('property_user')->where(['user_id' => $target->id, 'property_id' => $property->id])->update(['is_default' => true]);
        $result = app(UserLifecycleService::class)->activate($actor, $target->id, $property->id);
        $this->assertTrue($result->is_active);
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'USER_REACTIVATED')->count());
    }

    public function test_offboard_neutralizes_all_authority_before_soft_delete_and_denies_self(): void
    {
        [$actor, $target, $property] = $this->usersInOneProperty();
        $otherProperty = PropertyFactory::new()->create(['company_id' => $property->company_id]);
        $target->properties()->attach($otherProperty->id, ['is_default' => false, 'status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['name' => 'offboard-role', 'guard_name' => 'web', 'property_id' => $property->id]);
        $permission = Permission::query()->create(['name' => 'offboard.direct', 'guard_name' => 'web']);
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_id' => $target->id,
            'model_type' => $target->getMorphClass(),
            'property_id' => $property->id,
        ]);
        DB::table('model_has_permissions')->insert([
            'permission_id' => $permission->id,
            'model_id' => $target->id,
            'model_type' => $target->getMorphClass(),
            'property_id' => $property->id,
        ]);

        $result = app(UserLifecycleService::class)->offboard($actor, $target->id, $property->id);

        $this->assertTrue($result->trashed());
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertSame(0, DB::table('property_user')->where('user_id', $target->id)->where('status', 'active')->count());
        $this->assertSame(0, DB::table('property_user')->where('user_id', $target->id)->where('is_default', true)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $target->id)->count());
        $this->assertSame(0, DB::table('model_has_permissions')->where('model_id', $target->id)->count());
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'USER_OFFBOARDED')->count());

        $this->expectException(DomainException::class);
        app(UserLifecycleService::class)->offboard($actor, $actor->id, $property->id);
    }

    public function test_default_replacement_and_membership_removal_preserve_one_active_default(): void
    {
        [$actor, $target, $property] = $this->usersInOneProperty();
        $replacement = PropertyFactory::new()->create(['company_id' => $property->company_id]);
        $target->properties()->attach($replacement->id, ['is_default' => false, 'status' => 'active', 'joined_at' => now()]);

        app(UserLifecycleService::class)->setDefaultProperty($actor, $target->id, $property->id, $replacement->id);
        $this->assertSame(1, DB::table('property_user')->where('user_id', $target->id)->where('status', 'active')->where('is_default', true)->count());
        $this->assertDatabaseHas('property_user', ['user_id' => $target->id, 'property_id' => $replacement->id, 'is_default' => true]);

        app(UserLifecycleService::class)->removeMembership($actor, $target->id, $property->id, null);
        $this->assertDatabaseHas('property_user', ['user_id' => $target->id, 'property_id' => $property->id, 'status' => 'inactive', 'is_default' => false]);
        $this->assertSame(1, DB::table('property_user')->where('user_id', $target->id)->where('status', 'active')->where('is_default', true)->count());
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'PROPERTY_MEMBERSHIP_REMOVED')->count());
    }

    public function test_active_user_cannot_lose_last_membership(): void
    {
        [$actor, $target, $property] = $this->usersInOneProperty();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ACTIVE_USER_LAST_MEMBERSHIP_CANNOT_BE_REMOVED');
        app(UserLifecycleService::class)->removeMembership($actor, $target->id, $property->id, null);
    }

    private function usersInOneProperty(): array
    {
        $company = CompanyFactory::new()->create();
        $property = PropertyFactory::new()->create(['company_id' => $company->id]);
        $actor = UserFactory::new()->withProperty($property)->create(['is_active' => true]);
        $target = UserFactory::new()->withProperty($property)->create(['is_active' => true]);

        return [$actor, $target, $property];
    }
}
