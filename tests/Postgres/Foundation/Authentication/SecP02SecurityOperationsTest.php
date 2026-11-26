<?php

namespace Tests\Postgres\Foundation\Authentication;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authentication\Services\IdentitySecurityEventService;
use Modules\Foundation\Authentication\Services\SecurityOperationsReadService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\PostgresTestCase;

class SecP02SecurityOperationsTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_read_service_scopes_both_ledgers_and_excludes_sensitive_payloads(): void
    {
        [$actor, $property] = $this->authorizedActor();
        $otherCompany = CompanyFactory::new()->create();
        $otherProperty = PropertyFactory::new()->create(['company_id' => $otherCompany->id]);
        $otherActor = UserFactory::new()->withProperty($otherProperty)->create();
        $events = app(IdentitySecurityEventService::class);
        $ownEvent = $events->record('USER_DEACTIVATED', 'SUCCESS', [
            'actor_user_id' => $actor->id,
            'subject_user_id' => $actor->id,
            'company_id' => $property->company_id,
            'property_id' => $property->id,
        ]);
        $events->record('USER_DEACTIVATED', 'SUCCESS', [
            'actor_user_id' => $otherActor->id,
            'subject_user_id' => $otherActor->id,
            'company_id' => $otherCompany->id,
            'property_id' => $otherProperty->id,
        ]);
        $ownAudit = $this->audit($actor, $property, 'sensitive_action_confirmed', 'secret-must-not-appear');
        $this->audit($otherActor, $otherProperty, 'fx_break_glass_activated', 'other-company-secret');
        $this->audit($actor, $property, 'unrelated_action', 'unrelated-secret');

        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $result = app(SecurityOperationsReadService::class)->forActor($actor);

        $this->assertSame($property->name, $result['property']);
        $this->assertSame(1, $result['identity_events']->total());
        $this->assertSame($ownEvent->id, $result['identity_events']->items()[0]['id']);
        $this->assertSame(1, $result['operational_events']->total());
        $this->assertSame($ownAudit->id, $result['operational_events']->items()[0]['id']);
        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-must-not-appear', $payload);
        $this->assertStringNotContainsString('other-company-secret', $payload);
        $this->assertStringNotContainsString('old_values', $payload);
        $this->assertStringNotContainsString('metadata', $payload);

        app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
        $this->expectException(HttpException::class);
        app(SecurityOperationsReadService::class)->forActor($actor);
    }

    public function test_read_service_denies_actor_without_audit_permission(): void
    {
        [, $property] = $this->authorizedActor();
        $unprivileged = UserFactory::new()->withProperty($property)->create();
        app(CurrentPropertyService::class)->setPropertyId($property->id);

        $this->expectException(HttpException::class);
        app(SecurityOperationsReadService::class)->forActor($unprivileged);
    }

    public function test_read_service_rejects_stale_actor_after_deactivation(): void
    {
        [$actor, $property] = $this->authorizedActor();
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        $this->assertTrue($actor->is_active);

        $this->expectException(HttpException::class);
        app(SecurityOperationsReadService::class)->forActor($actor);
    }

    public function test_authenticated_web_workspace_uses_canonical_property_and_bounded_projection(): void
    {
        [$actor, $property] = $this->authorizedActor();
        app(IdentitySecurityEventService::class)->record('USER_REACTIVATED', 'SUCCESS', [
            'actor_user_id' => $actor->id,
            'subject_user_id' => $actor->id,
            'company_id' => $property->company_id,
            'property_id' => $property->id,
        ]);
        $login = $this->withSession(['login.tenant_id' => $property->company_id])->post('/login', [
            'email' => $actor->email,
            'password' => 'password',
        ]);
        $login->assertRedirect('/frontdesk');
        $cookie = $login->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie(config('session.cookie'), $cookie->getValue());

        $this->get('/security/operations?event_type=USER_REACTIVATED')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Foundation/SecurityOperations/Index')
                ->where('property', $property->name)
                ->where('filters.event_type', 'USER_REACTIVATED')
                ->has('identity_events.data', 1)
                ->has('operational_events.data', 0)
                ->etc());
    }

    private function authorizedActor(): array
    {
        $company = CompanyFactory::new()->create();
        $property = PropertyFactory::new()->create(['company_id' => $company->id]);
        $actor = UserFactory::new()->withProperty($property)->create(['is_active' => true]);
        $permission = Permission::query()->firstOrCreate(['name' => 'audit.view', 'guard_name' => 'web']);
        setPermissionsTeamId($property->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo($permission);

        return [$actor, $property];
    }

    private function audit(User $actor, Property $property, string $event, string $secret): AuditLog
    {
        return AuditLog::record([
            'property_id' => $property->id,
            'user_id' => $actor->id,
            'event' => $event,
            'auditable_type' => User::class,
            'auditable_id' => $actor->id,
            'old_values' => ['raw_token' => $secret],
            'new_values' => ['reason' => $secret],
        ]);
    }
}
