<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Department\Models\Department;
use Shared\Exceptions\PropertyNotResolvedException;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class CurrentPropertyServiceTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    private CurrentPropertyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CurrentPropertyService::class);
        $this->service->clear();
        session()->forget('current_property_id');
    }

    // ─── Tier resolution order ────────────────────────────────────────────────

    public function test_resolves_from_authenticated_user_property_id(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);

        $this->assertSame($property->id, $this->service->getPropertyId());
    }

    public function test_session_takes_precedence_over_auth_user(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'PB01']);
        $admin   = $this->createPropertyAdmin($propA);

        $this->actingAs($admin);
        session(['current_property_id' => $propB->id]);

        $this->assertSame($propB->id, $this->service->getPropertyId());
    }

    public function test_explicit_override_takes_precedence_over_session(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'PB02']);
        $propC   = $this->createProperty($company, ['code' => 'PC02']);
        $admin   = $this->createPropertyAdmin($propA);

        $this->actingAs($admin);
        session(['current_property_id' => $propB->id]);
        $this->service->setPropertyId($propC->id);

        $this->assertSame($propC->id, $this->service->getPropertyId());
    }

    public function test_returns_null_when_no_context_is_available(): void
    {
        $this->assertNull($this->service->getPropertyId());
    }

    // ─── clear() ─────────────────────────────────────────────────────────────

    public function test_clear_removes_explicit_override_and_falls_back_to_auth_user(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'PB03']);
        $admin   = $this->createPropertyAdmin($propA);

        $this->actingAs($admin);
        $this->service->setPropertyId($propB->id);
        $this->service->clear();

        $this->assertSame($propA->id, $this->service->getPropertyId());
    }

    // ─── resolveOrFail() ─────────────────────────────────────────────────────

    public function test_resolve_or_fail_returns_id_when_context_is_resolved(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);

        $this->assertSame($property->id, $this->service->resolveOrFail());
    }

    public function test_resolve_or_fail_throws_when_no_context(): void
    {
        $this->expectException(PropertyNotResolvedException::class);
        $this->expectExceptionMessage('Property context could not be resolved.');

        $this->service->resolveOrFail();
    }

    // ─── Backward-compatible aliases ──────────────────────────────────────────

    public function test_set_id_and_get_id_remain_functional(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);

        $this->service->setId($property->id);

        $this->assertSame($property->id, $this->service->getId());
        $this->assertTrue($this->service->isResolved());
        $this->assertSame($property->id, $this->service->resolve());
    }

    // ─── BelongsToProperty integration ───────────────────────────────────────

    public function test_property_scoped_model_auto_assigns_property_from_auth_user(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);

        $dept = Department::create(['name' => 'Auto Dept', 'code' => 'AUTOD']);

        $this->assertSame($property->id, $dept->property_id);
    }

    public function test_property_scoped_model_auto_assigns_from_explicit_override(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);

        $this->service->setPropertyId($property->id);

        $dept = Department::create(['name' => 'Override Dept', 'code' => 'OVRD1']);

        $this->assertSame($property->id, $dept->property_id);
    }

    public function test_property_scoped_model_creation_fails_without_context(): void
    {
        $this->expectException(PropertyNotResolvedException::class);

        Department::create(['name' => 'No Context Dept', 'code' => 'NOCTX']);
    }

    public function test_super_admin_without_property_context_fails_on_scoped_model_creation(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->actingAs($superAdmin);
        // super admin has property_id = null — no tier resolves

        $this->expectException(PropertyNotResolvedException::class);

        Department::create(['name' => 'SA Dept', 'code' => 'SADMN']);
    }

    public function test_explicitly_provided_property_id_bypasses_service(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        // no auth, no session, no override — but explicit in data

        $dept = Department::create([
            'property_id' => $property->id,
            'name'        => 'Explicit Dept',
            'code'        => 'EXPLT',
        ]);

        $this->assertSame($property->id, $dept->property_id);
    }
}
