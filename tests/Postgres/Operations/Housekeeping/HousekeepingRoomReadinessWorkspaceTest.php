<?php

namespace Tests\Postgres\Operations\Housekeeping;

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
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class HousekeepingRoomReadinessWorkspaceTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private Property $otherTenantProperty;
    private User $housekeepingActor;
    private User $housekeepingInspector;
    private User $frontDeskActor;
    private User $engineeringActor;
    private User $financeActor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));

        $this->company = Company::create([
            'name' => 'HK Workspace Company',
            'slug' => 'hk-workspace-company-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'HK Workspace Property',
            'slug' => 'hk-workspace-property-' . Str::lower(Str::random(6)),
            'code' => 'HKWS' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $otherCompany = Company::create([
            'name' => 'HK Workspace Other Company',
            'slug' => 'hk-ws-other-co-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'HK Workspace Other Property',
            'slug' => 'hk-ws-other-prop-' . Str::lower(Str::random(6)),
            'code' => 'HKWO' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherTenantProperty = Property::create([
            'company_id' => $otherCompany->id,
            'name' => 'HK Workspace Cross Tenant',
            'slug' => 'hk-ws-xtenant-' . Str::lower(Str::random(6)),
            'code' => 'HKXT' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->housekeepingActor = User::create([
            'name' => 'HK Workspace Actor',
            'email' => 'hk-ws-actor@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->housekeepingInspector = User::create([
            'name' => 'HK Workspace Inspector',
            'email' => 'hk-ws-inspector@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->frontDeskActor = User::create([
            'name' => 'FD Workspace Actor',
            'email' => 'fd-ws-actor@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->engineeringActor = User::create([
            'name' => 'ENG Workspace Actor',
            'email' => 'eng-ws-actor@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->financeActor = User::create([
            'name' => 'FIN Workspace Actor',
            'email' => 'fin-ws-actor@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        foreach ([$this->housekeepingActor, $this->housekeepingInspector, $this->frontDeskActor, $this->engineeringActor, $this->financeActor] as $user) {
            $user->properties()->attach($this->property->id, [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        foreach ([
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
            HousekeepingRoomReadinessProjectionService::FRONT_DESK_VIEW_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
            'engineering.room-availability.view',
            'finance.journal-entry.post',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->housekeepingActor->givePermissionTo([
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
        ]);

        $this->housekeepingInspector->givePermissionTo([
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);

        $this->frontDeskActor->givePermissionTo(
            HousekeepingRoomReadinessProjectionService::FRONT_DESK_VIEW_PERMISSION
        );

        $this->engineeringActor->givePermissionTo('engineering.room-availability.view');
        $this->financeActor->givePermissionTo('finance.journal-entry.post');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_housekeeping_workspace_access_denied(): void
    {
        $this->get('/housekeeping/room-readiness')->assertRedirect();
    }

    public function test_active_property_required(): void
    {
        $this->withSession([])
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness')
            ->assertRedirect();
    }

    public function test_workspace_renders_room_readiness_surface_for_authorized_actor(): void
    {
        $dirtyId = $this->createRoom('101', 'dirty', 'waiting_cleaning');
        $cleanId = $this->createRoom('102', 'clean', 'waiting_inspection');
        $readyId = $this->createRoom('103', 'inspected', 'ready_for_sale');

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness?fake_param=malicious&status=hacked')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ivorq/Housekeeping/HousekeepingWorkspace')
                ->where('activeTab', 'room_readiness')
            );

        $readinessRows = $response->inertiaProps('readinessRows');

        $this->assertIsArray($readinessRows);
        $this->assertGreaterThanOrEqual(3, count($readinessRows));

        $byRoomNumber = collect($readinessRows)->keyBy('room_number');
        $this->assertArrayHasKey('101', $byRoomNumber->all());
        $this->assertArrayHasKey('102', $byRoomNumber->all());
        $this->assertArrayHasKey('103', $byRoomNumber->all());

        $this->assertSame('waiting_cleaning', $byRoomNumber['101']['readiness_state']);
        $this->assertSame('waiting_inspection', $byRoomNumber['102']['readiness_state']);
        $this->assertSame('ready_for_sale', $byRoomNumber['103']['readiness_state']);
        $this->assertSame('inspected', $byRoomNumber['103']['cleanliness_status']);
    }

    public function test_workspace_shows_readiness_statuses_through_housekeeping_projection(): void
    {
        $this->createRoom('201', 'dirty', 'waiting_cleaning');
        $this->createRoom('202', 'clean', 'waiting_inspection');
        $this->createRoom('203', 'inspected', 'ready_for_sale');

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness')
            ->assertOk();

        $readinessRows = $response->inertiaProps('readinessRows');

        $states = collect($readinessRows)->pluck('readiness_state')->unique()->values()->all();
        $this->assertContains('waiting_cleaning', $states);
        $this->assertContains('waiting_inspection', $states);
        $this->assertContains('ready_for_sale', $states);

        foreach ($readinessRows as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('room_number', $row);
            $this->assertArrayHasKey('readiness_state', $row);
            $this->assertArrayHasKey('cleanliness_status', $row);
        }
    }

    public function test_workspace_exposes_allowed_next_actions_according_to_permission(): void
    {
        $this->createRoom('301', 'dirty', 'waiting_cleaning');
        $this->createRoom('302', 'clean', 'waiting_inspection');

        // Housekeeping actor has clean + submit-inspection, not release-ready
        $response = $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeTab', 'room_readiness')
            );

        $content = $response->getContent();

        // Workspace must not expose release-ready action for actor without the permission
        $this->assertStringNotContainsString('Release Ready', $content, 'Workspace must not expose Release Ready for actor without release-ready permission.');
    }

    public function test_workspace_does_not_expose_finance_or_restricted_controls(): void
    {
        $this->createRoom('401', 'inspected', 'ready_for_sale');

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness')
            ->assertOk();

        $content = $response->getContent();

        $restrictedTerms = [
            'Folio',
            'folio',
            'Payment',
            'payment',
            'Revenue',
            'revenue',
            'Tax',
            'AR',
            'GL',
            'Night Audit',
            'Cashier',
            'Banking',
            'Financial Period',
            'Business Date',
            'Cost Ledger',
        ];

        foreach ($restrictedTerms as $term) {
            $this->assertStringNotContainsString($term, $content, "Workspace must not expose restricted term: {$term}");
        }
    }

    public function test_front_desk_cannot_mutate_housekeeping_readiness_through_workspace(): void
    {
        $roomId = $this->createRoom('501', 'dirty', 'waiting_cleaning');

        $this->withSession($this->propertySession())
            ->actingAs($this->frontDeskActor, 'web')
            ->post('/operations/room-readiness/start-cleaning', [
                'room_id' => $roomId,
                'idempotency_key' => 'fd-ws-mutate-' . Str::ulid(),
            ])
            ->assertForbidden();
    }

    public function test_engineering_cannot_release_housekeeping_readiness_through_workspace(): void
    {
        $roomId = $this->createRoom('601', 'clean', 'waiting_inspection');

        $this->withSession($this->propertySession())
            ->actingAs($this->engineeringActor, 'web')
            ->post('/operations/room-readiness/release-ready', [
                'room_id' => $roomId,
                'release_reason' => 'Engineering attempt',
                'idempotency_context' => 'eng-ws-release-' . Str::ulid(),
            ])
            ->assertForbidden();
    }

    public function test_housekeeping_workspace_is_read_only_and_does_not_mutate_domain_tables(): void
    {
        $this->createRoom('701', 'dirty', 'waiting_cleaning');
        $this->createRoom('702', 'inspected', 'ready_for_sale');

        $before = $this->domainTableCounts();

        $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness?status=DIRTY&audit_actor=test&readiness_state=hacked')
            ->assertOk();

        $this->assertSame($before, $this->domainTableCounts());
    }

    public function test_cross_property_rooms_not_exposed_in_workspace(): void
    {
        $this->createRoom('801', 'inspected', 'ready_for_sale');

        $otherRoomId = (string) Str::ulid();
        DB::table('rooms')->insert([
            'id' => $otherRoomId,
            'property_id' => $this->otherProperty->id,
            'room_number' => 'X01',
            'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_sale',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness')
            ->assertOk();

        $readinessRows = $response->inertiaProps('readinessRows');
        $roomNumbers = collect($readinessRows)->pluck('room_number')->all();

        $this->assertContains('801', $roomNumbers);
        $this->assertNotContains('X01', $roomNumbers, 'Cross-property room must not appear in active property workspace.');
    }

    public function test_cross_tenant_rooms_not_exposed_in_workspace(): void
    {
        $this->createRoom('901', 'inspected', 'ready_for_sale');

        $xTenantRoomId = (string) Str::ulid();
        DB::table('rooms')->insert([
            'id' => $xTenantRoomId,
            'property_id' => $this->otherTenantProperty->id,
            'room_number' => 'T01',
            'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_sale',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->get('/housekeeping/room-readiness')
            ->assertOk();

        $readinessRows = $response->inertiaProps('readinessRows');
        $roomNumbers = collect($readinessRows)->pluck('room_number')->all();

        $this->assertContains('901', $roomNumbers);
        $this->assertNotContains('T01', $roomNumbers, 'Cross-tenant room must not appear in active property workspace.');
    }

    public function test_workspace_does_not_accept_post_mutation(): void
    {
        $this->createRoom('1001', 'dirty', 'waiting_cleaning');

        $this->withSession($this->propertySession())
            ->actingAs($this->housekeepingActor, 'web')
            ->post('/housekeeping/room-readiness', [
                'status' => 'ready_for_sale',
                'room_number' => '1001',
            ])
            ->assertMethodNotAllowed();
    }

    private function createRoom(string $number, string $cleanliness, string $readiness): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'room_number' => $number,
            'room_type' => 'deluxe',
            'cleanliness_status' => $cleanliness,
            'readiness_state' => $readiness,
            'occupancy_status' => 'vacant',
            'is_active' => true,
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
            'engineering_room_availability_blocks',
            'housekeeping_room_readiness_transitions',
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
