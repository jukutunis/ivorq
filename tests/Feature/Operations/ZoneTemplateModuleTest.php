<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class ZoneTemplateModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ─── Create ─────────────────────────────────────────────────────────────

    public function test_admin_can_create_zone_template(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin)
            ->post('/operations/zone-templates', [
                'template_name'    => 'Pool Template',
                'zone_type'        => 'recreation',
                'default_priority' => 2,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zone_templates', [
            'property_id'   => $property->id,
            'template_name' => 'Pool Template',
            'zone_type'     => 'recreation',
        ]);
    }

    public function test_staff_cannot_create_zone_template(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff)
            ->post('/operations/zone-templates', [
                'template_name' => 'Unauthorized Template',
                'zone_type'     => 'custom',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('zone_templates', [
            'template_name' => 'Unauthorized Template',
        ]);
    }

    // ─── Update ─────────────────────────────────────────────────────────────

    public function test_admin_can_update_zone_template(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $template = $this->createZoneTemplate($property);

        $this->actingAs($admin)
            ->put("/operations/zone-templates/{$template->id}", [
                'template_name'    => 'Updated Name',
                'zone_type'        => 'back_of_house',
                'default_priority' => 4,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zone_templates', [
            'id'            => $template->id,
            'template_name' => 'Updated Name',
            'zone_type'     => 'back_of_house',
        ]);
    }

    // ─── Delete ─────────────────────────────────────────────────────────────

    public function test_admin_can_delete_zone_template(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $template = $this->createZoneTemplate($property);

        $this->actingAs($admin)
            ->delete("/operations/zone-templates/{$template->id}")
            ->assertRedirect('/operations/zone-templates');

        $this->assertSoftDeleted('zone_templates', ['id' => $template->id]);
    }

    // ─── Uniqueness ─────────────────────────────────────────────────────────

    public function test_template_name_must_be_unique_per_property(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->createZoneTemplate($property, ['template_name' => 'Duplicate Template']);

        $this->actingAs($admin)
            ->post('/operations/zone-templates', [
                'template_name' => 'Duplicate Template',
                'zone_type'     => 'custom',
            ])
            ->assertSessionHasErrors('template_name');
    }

    public function test_same_template_name_allowed_across_different_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB20']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->createZoneTemplate($propertyA, ['template_name' => 'Shared Template']);

        $this->actingAs($adminB)
            ->post('/operations/zone-templates', [
                'template_name' => 'Shared Template',
                'zone_type'     => 'recreation',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zone_templates', [
            'property_id'   => $propertyB->id,
            'template_name' => 'Shared Template',
        ]);
    }

    // ─── Property isolation ─────────────────────────────────────────────────

    public function test_user_cannot_view_template_from_another_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB21']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $templateA = $this->createZoneTemplate($propertyA);

        $this->actingAs($adminB)
            ->get("/operations/zone-templates/{$templateA->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_update_template_from_another_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB22']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $templateA = $this->createZoneTemplate($propertyA);

        $this->actingAs($adminB)
            ->put("/operations/zone-templates/{$templateA->id}", [
                'template_name' => 'Hacked Template',
                'zone_type'     => 'custom',
            ])
            ->assertForbidden();
    }

    public function test_user_cannot_delete_template_from_another_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB23']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $templateA = $this->createZoneTemplate($propertyA);

        // BelongsToProperty global scope hides Template A from property B user → 404
        // (same behaviour as cross-property zone and assignment access)
        $this->actingAs($adminB)
            ->delete("/operations/zone-templates/{$templateA->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('zone_templates', ['id' => $templateA->id, 'deleted_at' => null]);
    }
}
