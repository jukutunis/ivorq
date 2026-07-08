<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class FrontDeskArrivalWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));

        $this->company = Company::create([
            'name' => 'FDA Workspace Company',
            'slug' => 'fda-workspace-company-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FDA Workspace Property',
            'slug' => 'fda-workspace-property-' . Str::lower(Str::random(6)),
            'code' => 'FDAW' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actor = User::create([
            'name' => 'FDA Workspace Actor',
            'email' => 'fda-workspace-actor@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
        Permission::firstOrCreate(['name' => ArrivalEligibilityProjectionService::VIEW_PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION, 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_arrival_access_denied(): void
    {
        $this->get('/frontdesk/arrivals')->assertRedirect();
    }

    public function test_arrival_view_permission_required(): void
    {
        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get('/frontdesk/arrivals')
            ->assertForbidden();
    }

    public function test_active_property_required(): void
    {
        $this->actor->givePermissionTo(ArrivalEligibilityProjectionService::VIEW_PERMISSION);
        $this->actor->givePermissionTo(EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION);

        $this->withSession([])
            ->actingAs($this->actor, 'web')
            ->get('/frontdesk/arrivals')
            ->assertRedirect();
    }

    public function test_arrival_workspace_renders_server_resolved_read_only_projection(): void
    {
        $this->actor->givePermissionTo(ArrivalEligibilityProjectionService::VIEW_PERMISSION);
        $this->actor->givePermissionTo(EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION);
        $guestId = $this->guest('Workspace Guest');
        $roomId = $this->room('901');
        $reservationId = $this->reservation($guestId, 'RES-FDA-WEB', 'confirmed', '2026-07-08', $roomId);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get('/frontdesk/arrivals?property_id=fake&blocked_reason=fake&reservation_status=cancelled')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ivorq/FrontDesk/FrontDeskWorkspace')
                ->where('activeTab', 'arrivals')
                ->where('arrivalWorkspace.property.id', $this->property->id)
                ->where('arrivalWorkspace.views.arrivingToday.0.reservation_id', $reservationId)
                ->where('arrivalWorkspace.views.arrivingToday.0.eligibility.eligible', true)
                ->where('arrivalWorkspace.views.arrivingToday.0.housekeeping.source', 'Housekeeping Room')
                ->where('arrivalWorkspace.views.arrivingToday.0.engineering.source', 'Engineering Availability Projection')
                ->where('arrivalWorkspace.policy.identityDocumentRequirement', 'Not configured by canonical source.')
                ->where('arrivalWorkspace.financeMarker', 'Financial settlement: Not evaluated in Front Desk Package A.')
            );
    }

    public function test_cross_property_reservation_is_not_returned_to_workspace(): void
    {
        $this->actor->givePermissionTo(ArrivalEligibilityProjectionService::VIEW_PERMISSION);
        $this->actor->givePermissionTo(EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION);

        $guestId = $this->guest('Visible Workspace Guest');
        $visibleReservation = $this->reservation($guestId, 'RES-FDA-VISIBLE', 'confirmed', '2026-07-08');

        $otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FDA Workspace Other Property',
            'slug' => 'fda-workspace-other-' . Str::lower(Str::random(6)),
            'code' => 'FDWO' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $otherGuest = $this->guest('Hidden Workspace Guest', $otherProperty);
        $this->reservation($otherGuest, 'RES-FDA-HIDDEN', 'confirmed', '2026-07-08', null, $otherProperty);

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get('/frontdesk/arrivals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('arrivalWorkspace.views.arrivingToday.0.reservation_id', $visibleReservation)
                ->where('arrivalWorkspace.snapshots.totalArrivals', 1)
            );
    }

    public function test_arrival_workspace_is_read_only_and_does_not_create_front_desk_or_finance_records(): void
    {
        $this->actor->givePermissionTo(ArrivalEligibilityProjectionService::VIEW_PERMISSION);
        $this->actor->givePermissionTo(EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION);
        $guestId = $this->guest('Read Only Workspace Guest');
        $this->reservation($guestId, 'RES-FDA-READONLY', 'confirmed', '2026-07-08');

        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get('/frontdesk/arrivals?guest_id=fake&status=IN_HOUSE&audit_actor=fake')
            ->assertOk();

        $this->assertSame($before, $this->domainTableCounts());
        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->post('/frontdesk/arrivals', ['status' => 'IN_HOUSE'])
            ->assertMethodNotAllowed();
    }

    private function guest(string $name, ?Property $property = null): string
    {
        $property ??= $this->property;
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

    private function room(string $number): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
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

    private function reservation(string $guestId, string $number, string $status, string $arrivalDate, ?string $roomId = null, ?Property $property = null): string
    {
        $property ??= $this->property;
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
    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->property->company_id,
            'current_property_id' => $this->property->id,
        ];
    }
}
