<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingCheckoutTurnoverIntakeData;
use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverWorkspaceSourceIntegrityTest extends PostgresTestCase
{
    use CreatesHousekeepingCheckoutTurnoverIntakeData;
    use RefreshDatabase;

    private const URL = '/operations/housekeeping/checkout-turnovers';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCheckoutTurnoverFixture();
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->property->id);
        Permission::firstOrCreate(['name' => 'housekeeping.room.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'housekeeping.task.view', 'guard_name' => 'web']);
        Permission::firstOrCreate([
            'name' => HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
            'guard_name' => 'web',
        ]);
        $this->actor->givePermissionTo('housekeeping.room.view');
    }

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_unauthenticated_and_unauthorized_requests_are_rejected(): void
    {
        $this->get(self::URL)->assertRedirect();

        $unauthorized = User::create([
            'name' => 'Package 12 Unauthorized',
            'email' => 'p12-unauthorized-' . Str::lower(Str::random(5)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $unauthorized->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->workspaceAs($unauthorized)->assertForbidden();
    }

    public function test_inactive_current_property_fails_closed(): void
    {
        $this->property->forceFill(['is_active' => false])->save();

        $this->workspace()->assertNotFound();
    }

    public function test_other_property_is_absent_from_grid_kpis_filters_search_and_selected_detail(): void
    {
        $otherRoom = $this->p11Room($this->otherProperty, ['room_number' => 'B-SECRET-ROOM']);
        $other = $this->p11CheckoutSource($this->otherProperty, $otherRoom);

        $response = $this->workspace()->assertOk();
        $this->assertSame([], $response->inertiaProps('turnovers')['data']);
        $this->assertSame([
            'ready_now' => 0,
            'active_claims' => 0,
            'retry_waiting' => 0,
            'delivery_confirmation_pending' => 0,
            'completed_today' => 0,
            'review_required' => 0,
        ], $response->inertiaProps('kpis'));

        $this->assertSame([], $this->workspace(['state' => 'ready'])->assertOk()->inertiaProps('turnovers')['data']);
        $this->assertSame([], $this->workspace(['search' => 'B-SECRET-ROOM'])->assertOk()->inertiaProps('turnovers')['data']);
        $this->workspace(['selected' => $other['handoff']->id])->assertNotFound();
        $this->workspace(['selected' => (string) Str::ulid()])->assertNotFound();
    }

    public function test_client_property_id_is_rejected_and_cannot_change_scope(): void
    {
        $otherRoom = $this->p11Room($this->otherProperty, ['room_number' => 'B-FORGED']);
        $this->p11CheckoutSource($this->otherProperty, $otherRoom);

        $response = $this->withHeader('Accept', 'application/json')
            ->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(self::URL . '?property_id=' . $this->otherProperty->id)
            ->assertUnprocessable();

        $response->assertJsonValidationErrors('property_id');
    }

    public function test_complete_serialized_props_exclude_guest_pii_tokens_hashes_and_raw_database_details(): void
    {
        $room = $this->p11Room($this->property, ['room_number' => 'PII-ROOM']);
        $source = $this->p11CheckoutSource($this->property, $room);
        $source['guest']->forceFill([
            'full_name' => 'SENTINEL_GUEST_NAME_P12',
            'email' => 'sentinel-p12@example.test',
            'phone' => '+62-SENTINEL-P12',
            'notes' => 'SENTINEL_GUEST_ADDRESS_P12',
            'id_number' => 'SENTINEL_ID_DOCUMENT_P12',
        ])->save();

        $claim = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class)
            ->claimAvailable($this->property->id, $source['handoff']->id, 60);
        $persisted = DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('property_id', $this->property->id)
            ->where('id', $source['handoff']->id)
            ->first();

        $props = $this->workspace(['selected' => $source['handoff']->id])->assertOk()->inertiaProps();
        $serialized = json_encode($props, JSON_THROW_ON_ERROR);

        foreach ([
            'SENTINEL_GUEST_NAME_P12',
            'sentinel-p12@example.test',
            '+62-SENTINEL-P12',
            'SENTINEL_GUEST_ADDRESS_P12',
            'SENTINEL_ID_DOCUMENT_P12',
            $claim['claim_token'],
            $persisted->claim_token_hash,
            $source['handoff']->source_hash,
            $source['execution']->source_hash,
            'SQLSTATE',
            'Stack trace',
            'QueryException',
            'PDOException',
        ] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $serialized);
        }

        $keys = $this->recursiveKeys($props);
        foreach ([
            'guest_name',
            'guest_email',
            'guest_phone',
            'guest_address',
            'claim_token',
            'claim_token_hash',
            'source_hash',
            'handoff_source_hash',
            'checkout_execution_source_hash',
            'intake_source_hash',
            'stack_trace',
            'exception',
            'database_message',
        ] as $forbiddenKey) {
            $this->assertNotContains($forbiddenKey, $keys);
        }
    }

    public function test_get_workspace_creates_zero_durable_writes_or_lifecycle_changes(): void
    {
        $room = $this->p11Room($this->property, ['room_number' => 'ZERO-WRITE']);
        $source = $this->p11CheckoutSource($this->property, $room);
        $beforeHandoff = DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('property_id', $this->property->id)
            ->where('id', $source['handoff']->id)
            ->first();
        $beforeRoom = DB::table('rooms')->where('property_id', $this->property->id)->where('id', $room)->first();
        $beforeCounts = $this->durableCounts();

        $this->workspace(['selected' => $source['handoff']->id, 'search' => 'ZERO-WRITE'])->assertOk();

        $afterHandoff = DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('property_id', $this->property->id)
            ->where('id', $source['handoff']->id)
            ->first();
        $afterRoom = DB::table('rooms')->where('property_id', $this->property->id)->where('id', $room)->first();

        foreach ([
            'delivery_status',
            'attempts',
            'available_at',
            'claimed_at',
            'claim_expires_at',
            'delivered_at',
            'failed_at',
            'last_error_code',
        ] as $column) {
            $this->assertEquals($beforeHandoff->{$column}, $afterHandoff->{$column}, $column);
        }
        foreach (['readiness_state', 'cleanliness_status', 'updated_at'] as $column) {
            $this->assertEquals($beforeRoom->{$column}, $afterRoom->{$column}, $column);
        }
        $this->assertSame($beforeCounts, $this->durableCounts());
    }

    public function test_workspace_has_no_mutation_route_and_source_calls_no_lifecycle_service(): void
    {
        $this->workspace([], false, 'post')->assertMethodNotAllowed();

        $files = [
            base_path('Modules/Operations/Housekeeping/Http/Controllers/HousekeepingCheckoutTurnoverWorkspaceController.php'),
            base_path('Modules/Operations/Housekeeping/Http/Requests/HousekeepingCheckoutTurnoverWorkspaceRequest.php'),
            base_path('Modules/Operations/Housekeeping/Services/HousekeepingCheckoutTurnoverWorkspaceQuery.php'),
            base_path('Modules/Operations/Housekeeping/routes/web.php'),
            base_path('resources/js/Pages/Operations/Housekeeping/CheckoutTurnovers/Index.tsx'),
        ];
        $source = implode("\n", array_map('file_get_contents', $files));

        foreach ([
            'consumeNextAvailable(',
            'consumeClaimed(',
            'claimNextAvailable(',
            'claimAvailable(',
            'markDelivered(',
            'markFailed(',
            'FrontDeskCheckoutExecutionService',
            'ConsumeCheckoutTurnoverHandoffsCommand',
            'Http::',
            'ShouldQueue',
            'Schedule::',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $routes = file_get_contents(base_path('Modules/Operations/Housekeeping/routes/web.php'));
        $this->assertSame(1, substr_count($routes, "Route::get('housekeeping/checkout-turnovers'"));
        $this->assertStringNotContainsString("Route::post('housekeeping/checkout-turnovers'", $routes);
    }

    /**
     * @return array<string, int>
     */
    private function durableCounts(): array
    {
        $tables = [
            'housekeeping_checkout_turnover_intakes',
            'cleaning_tasks',
            'housekeeping_room_readiness_transitions',
            'audit_logs',
            'activity_log',
        ];

        return collect($tables)
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    /**
     * @return string[]
     */
    private function recursiveKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $keys = [...$keys, ...$this->recursiveKeys($item)];
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function workspace(
        array $query = [],
        bool $json = false,
        string $method = 'get',
    ): \Illuminate\Testing\TestResponse {
        $request = $this->withSession($this->propertySession())->actingAs($this->actor, 'web');
        if ($json) {
            $request->withHeader('Accept', 'application/json');
        }
        $url = self::URL . ($query === [] ? '' : '?' . http_build_query($query));

        return $request->{$method}($url);
    }

    private function workspaceAs(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->withSession($this->propertySession())
            ->actingAs($user, 'web')
            ->get(self::URL);
    }

    /**
     * @return array<string, string>
     */
    private function propertySession(): array
    {
        return [
            'active_company_id' => $this->company->id,
            'active_property_id' => $this->property->id,
            'current_property_id' => $this->property->id,
        ];
    }
}
