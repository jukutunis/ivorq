<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Activity\Models\ActivityLog;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Models\Zone;
use Modules\Operations\Zoning\Models\ZoneHistory;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class ZoneModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ─── Role-based creation ────────────────────────────────────────────────

    public function test_super_admin_can_create_zone(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        // Super-admin has null property_id; set property context explicitly
        // so CurrentPropertyService resolves the target property.
        app(\Shared\Services\CurrentPropertyService::class)->setId($property->id);

        $this->actingAs($superAdmin)
            ->post('/operations/zones', [
                'zone_code' => 'SA-01',
                'zone_name' => 'Super Admin Zone',
                'zone_type' => 'custom',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zones', [
            'property_id' => $property->id,
            'zone_code'   => 'SA-01',
        ]);
    }

    public function test_property_admin_can_create_zone(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin)
            ->post('/operations/zones', [
                'zone_code' => 'PA-01',
                'zone_name' => 'Admin Zone',
                'zone_type' => 'public_area',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zones', [
            'property_id' => $property->id,
            'zone_code'   => 'PA-01',
        ]);
    }

    public function test_manager_can_create_zone(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $manager  = $this->createManager($property);

        $this->actingAs($manager)
            ->post('/operations/zones', [
                'zone_code' => 'MG-01',
                'zone_name' => 'Manager Zone',
                'zone_type' => 'recreation',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zones', [
            'property_id' => $property->id,
            'zone_code'   => 'MG-01',
        ]);
    }

    public function test_staff_cannot_create_zone(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff)
            ->post('/operations/zones', [
                'zone_code' => 'ST-01',
                'zone_name' => 'Staff Zone',
                'zone_type' => 'custom',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('zones', ['zone_code' => 'ST-01']);
    }

    // ─── Uniqueness ─────────────────────────────────────────────────────────

    public function test_zone_code_must_be_unique_per_property(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->createZone($property, ['zone_code' => 'DUPE']);

        $this->actingAs($admin)
            ->post('/operations/zones', [
                'zone_code' => 'DUPE',
                'zone_name' => 'Duplicate',
                'zone_type' => 'custom',
            ])
            ->assertSessionHasErrors('zone_code');
    }

    public function test_same_zone_code_allowed_across_different_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->createZone($propertyA, ['zone_code' => 'SHARED']);

        $this->actingAs($adminB)
            ->post('/operations/zones', [
                'zone_code' => 'SHARED',
                'zone_name' => 'Zone in Property B',
                'zone_type' => 'custom',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zones', [
            'property_id' => $propertyB->id,
            'zone_code'   => 'SHARED',
        ]);
    }

    // ─── Status transitions ─────────────────────────────────────────────────

    public function test_draft_to_active_transition_succeeds(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $zone     = $this->createZone($property);

        $this->actingAs($admin)
            ->post("/operations/zones/{$zone->id}/status", [
                'status'  => ZoneStatusEnum::Active->value,
                'remarks' => 'Activated for operations',
            ])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Zone status changed to Active.']);

        $this->assertDatabaseHas('zones', [
            'id'     => $zone->id,
            'status' => ZoneStatusEnum::Active->value,
        ]);

        $this->assertDatabaseHas('zone_histories', [
            'zone_id' => $zone->id,
            'action'  => 'zone_activated',
        ]);
    }

    public function test_active_to_archived_transition_succeeds(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $zone     = $this->createActiveZone($property);

        $this->actingAs($admin)
            ->post("/operations/zones/{$zone->id}/status", [
                'status' => ZoneStatusEnum::Archived->value,
            ])
            ->assertOk();

        $this->assertDatabaseHas('zones', [
            'id'     => $zone->id,
            'status' => ZoneStatusEnum::Archived->value,
        ]);
    }

    public function test_archived_to_active_transition_fails(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $zone     = $this->createActiveZone($property);
        $zone->update(['status' => ZoneStatusEnum::Archived->value]);

        // Force JSON so ValidationException returns 422 rather than 302 redirect.
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/operations/zones/{$zone->id}/status", [
                'status' => ZoneStatusEnum::Active->value,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('zones', [
            'id'     => $zone->id,
            'status' => ZoneStatusEnum::Archived->value,
        ]);
    }

    // ─── Property isolation ─────────────────────────────────────────────────

    public function test_user_cannot_access_zone_from_another_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $zoneA     = $this->createZone($propertyA);

        // GET — BelongsToProperty global scope hides zone A from property B user
        $this->actingAs($adminB)
            ->get("/operations/zones/{$zoneA->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_update_zone_from_another_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB03']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $zoneA     = $this->createZone($propertyA);

        $this->actingAs($adminB)
            ->put("/operations/zones/{$zoneA->id}", [
                'zone_code' => 'HACK',
                'zone_name' => 'Hacked Zone',
                'zone_type' => 'custom',
            ])
            ->assertForbidden();
    }

    // ─── Soft delete ────────────────────────────────────────────────────────

    public function test_delete_zone_soft_deletes(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $zone     = $this->createZone($property);

        $this->actingAs($admin)
            ->delete("/operations/zones/{$zone->id}")
            ->assertRedirect('/operations/zones');

        $this->assertSoftDeleted('zones', ['id' => $zone->id]);
        $this->assertDatabaseMissing('zones', ['id' => $zone->id, 'deleted_at' => null]);
    }

    // ─── Audit trail ────────────────────────────────────────────────────────

    public function test_creating_zone_generates_audit_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin)
            ->post('/operations/zones', [
                'zone_code' => 'AUD-01',
                'zone_name' => 'Audit Zone',
                'zone_type' => 'custom',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Zone::class,
            'event'          => 'created',
        ]);
    }

    public function test_creating_zone_generates_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin)
            ->post('/operations/zones', [
                'zone_code' => 'ACT-01',
                'zone_name' => 'Activity Zone',
                'zone_type' => 'custom',
            ]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Zone::class,
        ]);
    }

    public function test_creating_zone_generates_zone_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin)
            ->post('/operations/zones', [
                'zone_code' => 'HST-01',
                'zone_name' => 'History Zone',
                'zone_type' => 'custom',
            ]);

        $zone = Zone::where('zone_code', 'HST-01')->first();

        $this->assertDatabaseHas('zone_histories', [
            'zone_id' => $zone->id,
            'action'  => 'zone_created',
        ]);
    }
}
