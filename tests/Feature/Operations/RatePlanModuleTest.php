<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Services\RatePlanService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class RatePlanModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    private function boot(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_rate_plan_persists_to_database(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RatePlanService::class);

        $plan = $service->create([
            'property_id' => $property->id,
            'rate_code'   => 'BAR',
            'rate_name'   => 'Best Available Rate',
            'plan_type'   => RatePlanTypeEnum::Nightly->value,
            'base_rate'   => 850000.00,
            'currency'    => 'IDR',
            'is_active'   => true,
        ]);

        $this->assertInstanceOf(RatePlan::class, $plan);
        $this->assertDatabaseHas('rate_plans', [
            'property_id' => $property->id,
            'rate_code'   => 'BAR',
        ]);
    }

    public function test_create_rate_plan_is_active_by_default(): void
    {
        ['property' => $property] = $this->boot();
        $plan = $this->makePmsRatePlan($property, ['is_active' => true]);

        $this->assertTrue($plan->is_active);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_rate_plan_changes_fields(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RatePlanService::class);
        $plan    = $this->makePmsRatePlan($property);

        $updated = $service->update($plan->id, [
            'rate_name' => 'Updated Name',
            'base_rate' => 950000.00,
            'is_active' => false,
        ]);

        $this->assertSame('Updated Name', $updated->rate_name);
        $this->assertSame(950000.0, (float) $updated->base_rate);
        $this->assertFalse($updated->is_active);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_rate_plan_soft_deletes(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RatePlanService::class);
        $plan    = $this->makePmsRatePlan($property);

        $this->assertTrue($service->delete($plan->id));
        $this->assertSoftDeleted('rate_plans', ['id' => $plan->id]);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    public function test_rate_code_must_be_unique_per_property(): void
    {
        ['property' => $property] = $this->boot();

        $this->post('/operations/pms/rate-plans', [
            'rate_code' => 'DUPE',
            'rate_name' => 'First',
            'plan_type' => RatePlanTypeEnum::Nightly->value,
            'base_rate' => 100,
        ])->assertRedirect();

        $this->post('/operations/pms/rate-plans', [
            'rate_code' => 'DUPE',
            'rate_name' => 'Second',
            'plan_type' => RatePlanTypeEnum::Nightly->value,
            'base_rate' => 100,
        ])->assertSessionHasErrors('rate_code');
    }

    public function test_rate_code_can_be_reused_across_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RP-B01']);

        RatePlan::create(['property_id' => $propertyA->id, 'rate_code' => 'SHARED', 'rate_name' => 'A', 'plan_type' => 'nightly', 'base_rate' => 100]);
        RatePlan::create(['property_id' => $propertyB->id, 'rate_code' => 'SHARED', 'rate_name' => 'B', 'plan_type' => 'nightly', 'base_rate' => 100]);

        $this->assertDatabaseHas('rate_plans', ['property_id' => $propertyA->id, 'rate_code' => 'SHARED']);
        $this->assertDatabaseHas('rate_plans', ['property_id' => $propertyB->id, 'rate_code' => 'SHARED']);
    }

    // ── Active filter ─────────────────────────────────────────────────────────

    public function test_active_returns_only_active_rate_plans(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(RatePlanService::class);

        $active   = $this->makePmsRatePlan($property, ['is_active' => true]);
        $inactive = $this->makePmsRatePlan($property, ['is_active' => false]);

        $results = $service->active();

        $this->assertTrue($results->contains('id', $active->id));
        $this->assertFalse($results->contains('id', $inactive->id));
    }

    public function test_find_by_code_returns_matching_rate_plan(): void
    {
        ['property' => $property] = $this->boot();
        $plan = $this->makePmsRatePlan($property, ['rate_code' => 'FIND-ME']);

        $found = app(RatePlanService::class)->findByCode('FIND-ME');

        $this->assertNotNull($found);
        $this->assertSame($plan->id, $found->id);
    }

    // ── Policy & cross-property ────────────────────────────────────────────────

    public function test_property_admin_can_manage_own_rate_plan(): void
    {
        ['property' => $property] = $this->boot();
        $plan = $this->makePmsRatePlan($property);

        $this->assertTrue(Gate::inspect('update', $plan)->allowed());
        $this->assertTrue(Gate::inspect('delete', $plan)->allowed());
    }

    public function test_cross_property_policy_denies_rate_plan_management(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RP-PB-X']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $plan = $this->makePmsRatePlan($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $plan)->denied());
        $this->assertTrue(Gate::inspect('delete', $plan)->denied());
    }

    public function test_staff_cannot_manage_rate_plans(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', RatePlan::class)->denied());
    }
}
