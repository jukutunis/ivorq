<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class FrontDeskArrivalEligibilityTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private Property $property;
    private Property $otherProperty;
    private Property $otherTenantProperty;
    private User $actor;
    private ArrivalEligibilityProjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));

        $this->company = $this->company('FDA Company', 'fda-company');
        $this->otherCompany = $this->company('FDA Other Company', 'fda-other-company');
        $this->property = $this->property($this->company, 'FDA Property', 'fda-property', 'FDAP');
        $this->otherProperty = $this->property($this->company, 'FDA Other Property', 'fda-other-property', 'FDA2');
        $this->otherTenantProperty = $this->property($this->otherCompany, 'FDA Cross Tenant', 'fda-cross-tenant', 'FDAX');
        $this->actor = $this->user('FDA Actor', 'fda-actor@example.test');
        $this->attachProperty($this->actor, $this->property);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
        Permission::firstOrCreate(['name' => ArrivalEligibilityProjectionService::VIEW_PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION, 'guard_name' => 'web']);
        $this->actor->givePermissionTo(ArrivalEligibilityProjectionService::VIEW_PERMISSION);
        $this->actor->givePermissionTo(EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION);

        $this->service = app(ArrivalEligibilityProjectionService::class);
        session($this->propertySession($this->property));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_arrival_ready_reservation_appears_in_queue(): void
    {
        $guestId = $this->guest($this->property, 'Arrival Ready Guest');
        $reservationId = $this->reservation($this->property, $guestId, 'RES-FDA-READY', 'confirmed', '2026-07-08');

        $workspace = $this->service->workspace($this->actor, [
            'property_id' => $this->otherProperty->id,
            'reservation_status' => 'cancelled',
            'blocked_reason' => 'browser supplied',
        ]);

        $this->assertSame(1, $workspace['snapshots']['arrivalReady']);
        $this->assertSame($reservationId, $workspace['views']['arrivingToday'][0]['reservation_id']);
        $this->assertTrue($workspace['views']['arrivingToday'][0]['eligibility']['eligible']);
        $this->assertSame('confirmed', $workspace['views']['arrivingToday'][0]['reservation_status']);
    }

    public function test_cancelled_no_show_and_non_eligible_statuses_are_blocked(): void
    {
        $guestId = $this->guest($this->property, 'Blocked Guest');

        $this->reservation($this->property, $guestId, 'RES-FDA-CAN', 'cancelled', '2026-07-08');
        $this->reservation($this->property, $guestId, 'RES-FDA-NOS', 'no_show', '2026-07-08');
        $this->reservation($this->property, $guestId, 'RES-FDA-TEN', 'tentative', '2026-07-08');

        $workspace = $this->service->workspace($this->actor);

        $this->assertSame(0, $workspace['snapshots']['arrivalReady']);
        $this->assertSame(3, $workspace['snapshots']['blockedArrivals']);
        $this->assertStringContainsString('cancelled', implode(' ', $workspace['views']['blockedArrivals'][0]['eligibility']['blockers']));
        $this->assertStringContainsString('no-show', implode(' ', $workspace['views']['blockedArrivals'][1]['eligibility']['blockers']));
    }

    public function test_cross_property_and_cross_tenant_reservations_are_inaccessible(): void
    {
        $guestId = $this->guest($this->property, 'Visible Guest');
        $visibleReservation = $this->reservation($this->property, $guestId, 'RES-FDA-VIS', 'confirmed', '2026-07-08');

        $otherGuest = $this->guest($this->otherProperty, 'Other Property Guest');
        $this->reservation($this->otherProperty, $otherGuest, 'RES-FDA-OTHER', 'confirmed', '2026-07-08');

        $crossTenantGuest = $this->guest($this->otherTenantProperty, 'Cross Tenant Guest');
        $this->reservation($this->otherTenantProperty, $crossTenantGuest, 'RES-FDA-XTEN', 'confirmed', '2026-07-08');

        $workspace = $this->service->workspace($this->actor);
        $ids = collect($workspace['views']['arrivingToday'])->pluck('reservation_id')->all();

        $this->assertSame([$visibleReservation], $ids);
    }

    public function test_guest_linkage_and_future_arrival_are_server_resolved_blockers(): void
    {
        $guestId = $this->guest($this->property, 'Valid Guest');
        $otherGuestId = $this->guest($this->otherProperty, 'Wrong Property Guest');

        $this->reservation($this->property, $guestId, 'RES-FDA-FUT', 'confirmed', '2026-07-10');
        $this->reservation($this->property, $otherGuestId, 'RES-FDA-GUEST', 'confirmed', '2026-07-08');

        $workspace = $this->service->workspace($this->actor);
        $blockers = collect($workspace['views']['blockedArrivals'])
            ->map(fn (array $row) => implode(' ', $row['eligibility']['blockers']))
            ->implode(' ');

        $this->assertStringContainsString('Arrival date is not due', $blockers);
        $this->assertStringContainsString('Canonical primary guest linkage', $blockers);
    }

    public function test_existing_active_stay_prevents_duplicate_arrival_eligibility(): void
    {
        $guestId = $this->guest($this->property, 'Already In House');
        $roomId = $this->room($this->property, '701');
        $reservationId = $this->reservation($this->property, $guestId, 'RES-FDA-STAY', 'confirmed', '2026-07-08', $roomId);

        DB::table('stays')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'reservation_id' => $reservationId,
            'room_id' => $roomId,
            'guest_id' => $guestId,
            'status' => 'checked_in',
            'check_in_at' => now(),
            'expected_departure_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workspace = $this->service->workspace($this->actor);

        $this->assertSame(0, $workspace['snapshots']['arrivalReady']);
        $this->assertStringContainsString('Existing active stay evidence', implode(' ', $workspace['views']['blockedArrivals'][0]['eligibility']['blockers']));
    }

    public function test_arrival_projection_does_not_mutate_external_domains_or_finance(): void
    {
        $guestId = $this->guest($this->property, 'Read Only Guest');
        $roomId = $this->room($this->property, '801');
        $this->reservation($this->property, $guestId, 'RES-FDA-RO', 'confirmed', '2026-07-08', $roomId);

        $before = $this->domainTableCounts();

        $workspace = $this->service->workspace($this->actor, [
            'eligibility' => 'ready',
            'guest_id' => (string) Str::ulid(),
            'audit_actor' => (string) Str::ulid(),
            'occurred_at' => now()->subYear()->toISOString(),
        ]);

        $this->assertSame(1, $workspace['snapshots']['arrivalReady']);
        $this->assertSame($before, $this->domainTableCounts());
    }

    private function company(string $name, string $slug): Company
    {
        return Company::create([
            'name' => $name,
            'slug' => $slug . '-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
    }

    private function property(Company $company, string $name, string $slug, string $code): Property
    {
        return Property::create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => $slug . '-' . Str::lower(Str::random(6)),
            'code' => $code . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function attachProperty(User $user, Property $property): void
    {
        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function guest(Property $property, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('guests')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'guest_code' => 'GST-' . strtoupper(Str::random(6)),
            'full_name' => $name,
            'guest_type' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function room(Property $property, string $number): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'room_number' => $number,
            'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_arrival',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function reservation(Property $property, string $guestId, string $number, string $status, string $arrivalDate, ?string $roomId = null): string
    {
        $id = (string) Str::ulid();
        DB::table('reservations')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'reservation_number' => $number,
            'primary_guest_id' => $guestId,
            'adults' => 1,
            'children' => 0,
            'arrival_date' => $arrivalDate,
            'departure_date' => Carbon::parse($arrivalDate)->addDay()->toDateString(),
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => $status,
            'reserved_room_type' => 'deluxe',
            'assigned_room_id' => $roomId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array<string, int>
     */
    private function domainTableCounts(): array
    {
        $tables = [
            'reservations',
            'guests',
            'rooms',
            'room_blocks',
            'work_orders',
            'stays',
            'folios',
            'folio_items',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'gl_financial_periods',
            'property_business_dates',
        ];

        return collect($tables)
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function propertySession(Property $property): array
    {
        return [
            'active_property_id' => $property->id,
            'active_company_id' => $property->company_id,
            'current_property_id' => $property->id,
        ];
    }
}
