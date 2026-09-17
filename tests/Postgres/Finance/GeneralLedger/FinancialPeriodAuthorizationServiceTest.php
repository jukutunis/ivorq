<?php

namespace Tests\Postgres\Finance\GeneralLedger;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\GeneralLedger\Services\FinancialPeriodAuthorizationService;
use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class FinancialPeriodAuthorizationServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_permission_seeder_registers_only_authority_without_role_assignment(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION,
            'guard_name' => 'web',
        ]);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('role_has_permissions', 0);
        $this->assertDatabaseCount('model_has_roles', 0);
        $this->assertDatabaseCount('model_has_permissions', 0);

        $roleSeeder = file_get_contents(base_path('Modules/Foundation/Authorization/database/seeders/RoleSeeder.php'));
        $this->assertStringContainsString("'finance-controller'", $roleSeeder);
        $this->assertStringContainsString("'finance-manager'", $roleSeeder);
        $this->assertStringContainsString("'general-ledger-accountant'", $roleSeeder);
        $this->assertStringNotContainsString(FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION, $roleSeeder);
    }

    public function test_authorized_active_actor_returns_exact_current_property(): void
    {
        [$company, $property, $actor] = $this->authorizedContext();
        $this->assertDatabaseCount('gl_financial_periods', 0);

        $authorized = app(FinancialPeriodAuthorizationService::class)->authorizeInitialization($actor);

        $this->assertSame($property->id, $authorized->id);
        $this->assertSame($company->id, $authorized->company_id);
        $this->assertDatabaseCount('gl_financial_periods', 0);
    }

    public function test_unauthenticated_actor_fails_closed(): void
    {
        [, , $actor] = $this->authorizedContext();
        auth()->logout();

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_authenticated_user_different_from_explicit_actor_fails_closed(): void
    {
        [$company, $property, $actor] = $this->authorizedContext();
        $otherActor = $this->actorFor($property, true);

        $this->authenticate($actor, $company, $property);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($otherActor));
    }

    public function test_inactive_actor_fails_closed(): void
    {
        [, , $actor] = $this->authorizedContext();
        $actor->forceFill(['is_active' => false])->save();

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_missing_active_company_context_fails_closed(): void
    {
        [, , $actor] = $this->authorizedContext();
        session()->forget('active_company_id');

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_inactive_company_fails_closed(): void
    {
        [$company, , $actor] = $this->authorizedContext();
        $company->forceFill(['is_active' => false])->save();

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_missing_current_property_fails_closed(): void
    {
        $company = $this->company();
        $actor = $this->actorFor(null, true);
        $this->authenticate($actor, $company, null);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_inactive_property_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $property->forceFill(['is_active' => false])->save();

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_property_belonging_to_another_company_fails_closed(): void
    {
        [$company, , $actor] = $this->authorizedContext();
        $otherCompany = $this->company();
        $otherProperty = $this->property($otherCompany);
        $actor->properties()->attach($otherProperty->id, $this->activeMembership());
        app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
        session(['active_company_id' => $company->id]);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_missing_property_membership_fails_closed(): void
    {
        [$company, , $actor] = $this->authorizedContext();
        $unrelatedProperty = $this->property($company);
        app(CurrentPropertyService::class)->setPropertyId($unrelatedProperty->id);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_inactive_property_membership_fails_closed(): void
    {
        [, $property, $actor] = $this->authorizedContext();
        $actor->properties()->updateExistingPivot($property->id, ['status' => 'inactive']);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_missing_permission_fails_closed(): void
    {
        [$company, $property, $actor] = $this->authorizedContext();
        $actor->revokePermissionTo(FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION);
        $this->authenticate($actor, $company, $property);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_correct_permission_with_active_membership_passes(): void
    {
        [, $property, $actor] = $this->authorizedContext();

        $this->assertSame(
            $property->id,
            $this->service()->authorizeInitialization($actor)->id,
        );
    }

    public function test_permission_on_another_user_does_not_authorize_actor(): void
    {
        $company = $this->company();
        $property = $this->property($company);
        $actor = $this->actorFor($property, false);
        $this->actorFor($property, true);
        $this->authenticate($actor, $company, $property);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_property_a_authority_cannot_access_property_b_context(): void
    {
        [$company, , $actor] = $this->authorizedContext();
        $propertyB = $this->property($company);
        app(CurrentPropertyService::class)->setPropertyId($propertyB->id);

        $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
    }

    public function test_every_rejection_uses_the_same_non_sensitive_message(): void
    {
        [$company, $property, $actor] = $this->authorizedContext();
        $cases = [
            function (): void {
                auth()->logout();
            },
            function () use ($company, $property, $actor): void {
                $this->authenticate($actor, $company, $property);
                session()->forget('active_company_id');
            },
            function () use ($company, $property, $actor): void {
                $this->authenticate($actor, $company, $property);
                $actor->properties()->updateExistingPivot($property->id, ['status' => 'inactive']);
            },
        ];

        foreach ($cases as $mutate) {
            $mutate();
            $this->assertDenied(fn () => $this->service()->authorizeInitialization($actor));
        }
    }

    private function authorizedContext(): array
    {
        $company = $this->company();
        $property = $this->property($company);
        $actor = $this->actorFor($property, true);
        $this->authenticate($actor, $company, $property);

        return [$company, $property, $actor];
    }

    private function company(array $attributes = []): Company
    {
        return CompanyFactory::new()->create(array_merge([
            'is_active' => true,
        ], $attributes));
    }

    private function property(Company $company, array $attributes = []): Property
    {
        return PropertyFactory::new()->create(array_merge([
            'company_id' => $company->id,
            'is_active' => true,
        ], $attributes));
    }

    private function actorFor(?Property $property, bool $authorized): User
    {
        $factory = UserFactory::new();
        if ($property) {
            $factory = $factory->withProperty($property);
        }

        $actor = $factory->create([
            'is_active' => true,
            'is_system_admin' => false,
        ]);

        if ($authorized) {
            Permission::firstOrCreate([
                'name' => FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION,
                'guard_name' => 'web',
            ]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $actor->givePermissionTo(FinancialPeriodAuthorizationService::INITIALIZE_PERMISSION);
        }

        return $actor;
    }

    private function authenticate(User $actor, Company $company, ?Property $property): void
    {
        app(CurrentPropertyService::class)->clear();
        session()->forget(['active_property_id', 'current_property_id']);
        session(['active_company_id' => $company->id]);

        if ($property) {
            app(CurrentPropertyService::class)->setPropertyId($property->id);
        }

        auth()->login($actor);
        $this->actingAs($actor);
    }

    private function activeMembership(): array
    {
        return [
            'is_default' => false,
            'status' => 'active',
            'joined_at' => now(),
        ];
    }

    private function service(): FinancialPeriodAuthorizationService
    {
        return app(FinancialPeriodAuthorizationService::class);
    }

    private function assertDenied(callable $action): void
    {
        try {
            $action();
            $this->fail('Financial Period authorization must fail closed.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(FinancialPeriodAuthorizationService::FAILURE_MESSAGE, $exception->getMessage());
        }
    }
}
