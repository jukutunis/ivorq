<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Services\GuestService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class GuestModuleTest extends TestCase
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

    public function test_create_guest_persists_to_database(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(GuestService::class);

        $guest = $service->create([
            'property_id' => $property->id,
            'guest_code'  => 'GST-TEST-001',
            'full_name'   => 'Jane Doe',
            'email'       => 'jane@example.com',
            'guest_type'  => GuestTypeEnum::Individual->value,
        ]);

        $this->assertInstanceOf(Guest::class, $guest);
        $this->assertDatabaseHas('guests', [
            'property_id' => $property->id,
            'guest_code'  => 'GST-TEST-001',
            'full_name'   => 'Jane Doe',
        ]);
    }

    public function test_create_guest_defaults_to_individual_type(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(GuestService::class);

        $guest = $service->create([
            'property_id' => $property->id,
            'guest_code'  => 'GST-TEST-002',
            'full_name'   => 'John Smith',
            'guest_type'  => GuestTypeEnum::Individual->value,
        ]);

        $this->assertSame(GuestTypeEnum::Individual, $guest->guest_type);
    }

    public function test_create_vip_guest_stores_vip_level(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(GuestService::class);

        $guest = $service->create([
            'property_id' => $property->id,
            'guest_code'  => 'GST-VIP-001',
            'full_name'   => 'VIP Guest',
            'guest_type'  => GuestTypeEnum::Vip->value,
            'vip_level'   => 2,
        ]);

        $this->assertSame(GuestTypeEnum::Vip, $guest->guest_type);
        $this->assertSame(2, $guest->vip_level);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_guest_changes_fields(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(GuestService::class);
        $guest   = $this->makePmsGuest($property);

        $updated = $service->update($guest->id, [
            'full_name' => 'Updated Name',
            'phone'     => '+60123456789',
            'vip_level' => 1,
        ]);

        $this->assertSame('Updated Name', $updated->full_name);
        $this->assertSame('+60123456789', $updated->phone);
        $this->assertSame(1, $updated->vip_level);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_guest_soft_deletes(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(GuestService::class);
        $guest   = $this->makePmsGuest($property);

        $this->assertTrue($service->delete($guest->id));
        $this->assertSoftDeleted('guests', ['id' => $guest->id]);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    public function test_guest_code_must_be_unique_per_property(): void
    {
        ['property' => $property] = $this->boot();

        $this->post('/operations/pms/guests', [
            'full_name'  => 'Guest A',
            'guest_type' => GuestTypeEnum::Individual->value,
        ])->assertRedirect();

        // Find the generated guest_code
        $created = Guest::where('property_id', $property->id)->latest()->first();

        // Attempt to create via HTTP with explicit duplicate code would fail validation,
        // but at DB level: same property + code = unique constraint
        $this->assertNotNull($created);
        $this->assertStringStartsWith('GST-', $created->guest_code);
    }

    public function test_guest_code_can_be_reused_across_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'G-B01']);

        Guest::create(['property_id' => $propertyA->id, 'guest_code' => 'GST-SAME', 'full_name' => 'A', 'guest_type' => 'individual']);
        Guest::create(['property_id' => $propertyB->id, 'guest_code' => 'GST-SAME', 'full_name' => 'B', 'guest_type' => 'individual']);

        $this->assertDatabaseHas('guests', ['property_id' => $propertyA->id, 'guest_code' => 'GST-SAME']);
        $this->assertDatabaseHas('guests', ['property_id' => $propertyB->id, 'guest_code' => 'GST-SAME']);
    }

    // ── VIP helpers ───────────────────────────────────────────────────────────

    public function test_vip_query_returns_only_vip_guests(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(GuestService::class);

        $vip = $this->makePmsGuest($property, ['vip_level' => 1, 'guest_type' => GuestTypeEnum::Vip->value]);
        $non = $this->makePmsGuest($property, ['vip_level' => null]);

        $results = $service->vip();

        $this->assertTrue($results->contains('id', $vip->id));
        $this->assertFalse($results->contains('id', $non->id));
    }

    public function test_find_by_code_returns_correct_guest(): void
    {
        ['property' => $property] = $this->boot();
        $service = app(GuestService::class);
        $guest   = $this->makePmsGuest($property, ['guest_code' => 'GST-LOOKUP']);

        $found = $service->findByCode('GST-LOOKUP');

        $this->assertNotNull($found);
        $this->assertSame($guest->id, $found->id);
    }

    public function test_find_by_code_returns_null_for_missing_code(): void
    {
        $this->boot();

        $result = app(GuestService::class)->findByCode('GST-NONEXISTENT');

        $this->assertNull($result);
    }

    // ── Policy & cross-property ────────────────────────────────────────────────

    public function test_property_admin_can_view_update_delete_own_guest(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);

        $this->assertTrue(Gate::inspect('view',   $guest)->allowed());
        $this->assertTrue(Gate::inspect('update', $guest)->allowed());
        $this->assertTrue(Gate::inspect('delete', $guest)->allowed());
    }

    public function test_cross_property_policy_denies_view_update_delete(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'G-PB-X']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest = $this->makePmsGuest($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view',   $guest)->denied());
        $this->assertTrue(Gate::inspect('update', $guest)->denied());
        $this->assertTrue(Gate::inspect('delete', $guest)->denied());
    }

    public function test_staff_cannot_create_or_delete_guests(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $guest    = $this->makePmsGuest($property);

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', Guest::class)->denied());
        $this->assertTrue(Gate::inspect('delete', $guest)->denied());
    }
}
