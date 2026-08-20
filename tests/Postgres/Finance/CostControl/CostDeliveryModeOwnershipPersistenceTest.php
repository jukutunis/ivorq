<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostDeliveryModeOwnershipBootstrapService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Tests\PostgresTestCase;

class CostDeliveryModeOwnershipPersistenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $actor;

    private InventoryItem $item;

    private InventoryLocation $location;

    private FinancialPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A Ownership Persistence',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'CCP01A-OWN-'.Str::random(8),
            'name' => 'CC-P01A Ownership Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A Ownership Location '.Str::random(6),
            'type' => 'internal',
        ]);
        $this->period = FinancialPeriod::updateOrCreate(
            [
                'property_id' => $this->property->id,
                'period_year' => 2026,
                'period_month' => 8,
            ],
            ['status' => FinancialPeriodStatusEnum::Open]
        );
    }

    public function test_delivery_mode_enum_has_only_canonical_values(): void
    {
        $this->assertSame(['SYNCHRONOUS', 'DEFERRED'], array_column(CostDeliveryMode::cases(), 'value'));
    }

    public function test_fresh_migrations_seed_no_pilot_ownership_or_cutover_business_rows(): void
    {
        $this->assertDatabaseCount('cost_delivery_pilot_properties', 0);
        $this->assertDatabaseCount('cost_delivery_mode_ownerships', 0);
        $this->assertDatabaseCount('cost_delivery_cutovers', 0);
        $this->assertDatabaseCount('cost_delivery_cutover_scopes', 0);
        $this->assertDatabaseCount('cost_delivery_cutover_attempts', 0);
    }

    public function test_pilot_is_singleton_append_only_authorization_evidence(): void
    {
        $pilot = CostDeliveryPilotProperty::create([
            'pilot_slot' => 1,
            'property_id' => $this->property->id,
            'owner_approval_reference' => 'OWNER-CC-P01A-TEST',
            'authorized_by' => $this->actor->id,
            'authorized_at' => now(),
        ]);

        $this->assertSame(1, $pilot->pilot_slot);

        try {
            DB::transaction(fn () => DB::table('cost_delivery_pilot_properties')->where('id', $pilot->id)
                ->update(['owner_approval_reference' => 'CHANGED']));
            $this->fail('Pilot UPDATE must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable append-only evidence', $exception->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('cost_delivery_pilot_properties')->where('id', $pilot->id)->delete());
            $this->fail('Pilot DELETE must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable append-only evidence', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        CostDeliveryPilotProperty::create([
            'pilot_slot' => 1,
            'property_id' => $this->property->id,
            'owner_approval_reference' => 'OWNER-CC-P01A-DUPLICATE',
            'authorized_by' => $this->actor->id,
            'authorized_at' => now(),
        ]);
    }

    public function test_initial_ownership_is_synchronous_version_one_unique_and_immutable(): void
    {
        $groupId = $this->createEnrolledGroup();
        $ownership = DB::transaction(fn () => app(CostDeliveryModeOwnershipBootstrapService::class)
            ->bootstrap($groupId, $this->actor->id));

        $this->assertSame(CostDeliveryMode::Synchronous, $ownership->delivery_mode);
        $this->assertSame(1, $ownership->ownership_version);
        $this->assertNull($ownership->activated_cutover_id);

        $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'cost_delivery_mode_ownerships'"))
            ->pluck('indexname');
        $this->assertContains('uk_cdmo_property_item', $indexes);
        $this->assertContains('uk_cdmo_enrollment_group', $indexes);

        try {
            DB::transaction(fn () => DB::table('cost_delivery_mode_ownerships')->where('id', $ownership->id)
                ->update(['property_id' => (string) Str::ulid()]));
            $this->fail('Ownership identity UPDATE must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('identity and establishment provenance are immutable', $exception->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('cost_delivery_mode_ownerships')->where('id', $ownership->id)->delete());
            $this->fail('Ownership DELETE must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('deletion is prohibited', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('cost_delivery_mode_ownerships')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'enrollment_group_id' => $groupId,
            'delivery_mode' => 'SYNCHRONOUS',
            'ownership_version' => 1,
            'established_by' => $this->actor->id,
            'established_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_ownership_rejects_non_initial_insert_and_arbitrary_update(): void
    {
        $groupId = $this->createEnrolledGroup();

        try {
            DB::transaction(fn () => DB::table('cost_delivery_mode_ownerships')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'item_id' => $this->item->id,
                'enrollment_group_id' => $groupId,
                'delivery_mode' => 'DEFERRED',
                'ownership_version' => 2,
                'activated_cutover_id' => (string) Str::ulid(),
                'established_by' => $this->actor->id,
                'established_at' => now(),
                'changed_by' => $this->actor->id,
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->fail('Initial DEFERRED ownership must be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('initial ownership must be SYNCHRONOUS version 1', $exception->getMessage());
        }

        $ownership = DB::transaction(fn () => app(CostDeliveryModeOwnershipBootstrapService::class)
            ->bootstrap($groupId, $this->actor->id));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/only SYNCHRONOUS to DEFERRED/');
        DB::table('cost_delivery_mode_ownerships')->where('id', $ownership->id)
            ->update(['ownership_version' => 2, 'updated_at' => now()]);
    }

    public function test_costcontrol_migration_set_rolls_back_and_reapplies_cleanly(): void
    {
        $migration100 = require base_path('Modules/Finance/CostControl/database/migrations/2026_08_21_000100_create_cost_delivery_pilot_properties_table.php');
        $migration200 = require base_path('Modules/Finance/CostControl/database/migrations/2026_08_21_000200_create_cost_delivery_mode_ownerships_table.php');
        $migration300 = require base_path('Modules/Finance/CostControl/database/migrations/2026_08_21_000300_create_cost_delivery_cutover_evidence_tables.php');

        $migration300->down();
        $migration200->down();
        $migration100->down();

        $this->assertFalse(Schema::hasTable('cost_delivery_pilot_properties'));
        $this->assertFalse(Schema::hasTable('cost_delivery_mode_ownerships'));
        $this->assertFalse(Schema::hasTable('cost_delivery_cutovers'));

        $migration100->up();
        $migration200->up();
        $migration300->up();

        $this->assertTrue(Schema::hasTable('cost_delivery_pilot_properties'));
        $this->assertTrue(Schema::hasTable('cost_delivery_mode_ownerships'));
        $this->assertTrue(Schema::hasTable('cost_delivery_cutovers'));
        $this->assertDatabaseCount('cost_delivery_pilot_properties', 0);
        $this->assertDatabaseCount('cost_delivery_mode_ownerships', 0);
    }

    private function createEnrolledGroup(): string
    {
        $repository = app(CostAuthorityEnrollmentRepository::class);
        $scope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        $group = $repository->createDraft(
            ['property_id' => $this->property->id, 'item_id' => $this->item->id],
            [[
                'location_id' => $this->location->id,
                'valuation_scope' => $scope,
                'opening_quantity' => '0.0000',
                'opening_carrying_value' => '0.0000',
                'currency_code' => 'USD',
                'business_date' => '2026-08-01',
                'financial_period_id' => $this->period->id,
                'source_reference' => 'CC-P01A-TEST',
                'evidence_timestamp' => now(),
            ]]
        );
        DB::transaction(fn () => $repository->approve($group->id, $this->actor->id, now()));
        DB::table('cost_authority_enrollment_groups')->where('id', $group->id)->update([
            'status' => CostAuthorityEnrollmentStatusEnum::Enrolled->value,
            'enrolled_at' => now(),
            'updated_at' => now(),
        ]);

        return $group->id;
    }
}
