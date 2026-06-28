<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Services\TransferService;
use Modules\Finance\CostControl\Services\ControlledTransferValuationInvocationService;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationIntent;
use Modules\Finance\CostControl\ValueObjects\ControlledValuationCostLedgerIntent;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;

class ControlledTransferValuationInvocationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private TransferService $transferService;
    private ControlledTransferValuationInvocationService $invocationService;
    private CostAvcoStateRepository $stateRepository;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $locationSrc;
    private InventoryLocation $locationDest;
    private string $businessDate;
    private string $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transferService = app(TransferService::class);
        $this->invocationService = app(ControlledTransferValuationInvocationService::class);
        $this->stateRepository = app(CostAvcoStateRepository::class);

        $this->property = Property::first();

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'Transfer Invocation Test Category',
        ]);

        $this->item = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $category->id,
            'sku'                   => 'XFER-INVOKE-001',
            'name'                  => 'Transfer Invocation Test Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '25.0000', // legacy cost
            'is_active'             => true,
        ]);

        // Lexicographically: 'loc_src' sorts AFTER 'loc_dest'
        $this->locationDest = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'loc_dest'],
            ['type' => 'internal']
        );

        $this->locationSrc = InventoryLocation::firstOrCreate(
            ['property_id' => $this->property->id, 'name' => 'loc_src'],
            ['type' => 'internal']
        );

        $this->businessDate = '2026-06-28';
        $this->occurredAt = '2026-06-28 12:00:00';

        // Seed property business date open
        DB::table('property_business_dates')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'business_date' => $this->businessDate,
            'status' => 'open',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed financial period open
        DB::table('financial_periods')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedGroup(string $itemId, string $status = 'enrolled'): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $itemId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedSnapshot(string $groupId, string $locationId, string $qty = '10.0000', string $value = '100.0000'): string
    {
        $snapshotId = (string) Str::ulid();
        $valuationScope = "property:{$this->property->id}:location:{$locationId}:item:{$this->item->id}";
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id' => $snapshotId,
            'enrollment_group_id' => $groupId,
            'location_id' => $locationId,
            'valuation_scope' => $valuationScope,
            'opening_quantity' => $qty,
            'opening_carrying_value' => $value,
            'currency_code' => 'USD',
            'business_date' => $this->businessDate,
            'financial_period_id' => 'fp_1',
            'evidence_timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $snapshotId;
    }

    private function seedState(
        string $groupId,
        string $snapshotId,
        string $locationId,
        ?int $seq,
        ?string $businessDate,
        string $qty = '10.0000',
        string $value = '100.0000',
        string $wauc = '10.0000'
    ): void {
        $valuationScope = "property:{$this->property->id}:location:{$locationId}:item:{$this->item->id}";
        DB::table('cost_avco_states')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $locationId,
            'item_id' => $this->item->id,
            'valuation_scope' => $valuationScope,
            'on_hand_quantity' => $qty,
            'carrying_value' => $value,
            'weighted_average_unit_cost' => $wauc,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence' => $seq,
            'last_valuation_business_date' => $businessDate,
            'enrollment_group_id' => $groupId,
            'enrollment_scope_snapshot_id' => $snapshotId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedStock(string $locationId, string $qty): void
    {
        DB::table('inventory_stocks')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $locationId,
            'item_id' => $this->item->id,
            'physical_quantity' => $qty,
            'reserved_quantity' => '0.0000',
            'status' => 'in_stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 1. Valid all-enrolled transfer: locks states, uses locked WAUC, and posts atomically.
     */
    public function test_valid_enrolled_transfer_invocation_succeeds(): void
    {
        $groupId = $this->seedGroup($this->item->id);
        $snapSrcId = $this->seedSnapshot($groupId, $this->locationSrc->id, '10.0000', '100.0000');
        $this->seedState($groupId, $snapSrcId, $this->locationSrc->id, null, null, '10.0000', '100.0000', '10.0000'); // Source WAUC = 10.0000

        $snapDestId = $this->seedSnapshot($groupId, $this->locationDest->id, '5.0000', '40.0000');
        $this->seedState($groupId, $snapDestId, $this->locationDest->id, null, null, '5.0000', '40.0000', '8.0000');

        $this->seedStock($this->locationSrc->id, '10.0000');
        $this->seedStock($this->locationDest->id, '5.0000');

        $transfer = InventoryTransfer::create([
            'property_id'      => $this->property->id,
            'transfer_number'  => 'XFER-001',
            'from_location_id' => $this->locationSrc->id,
            'to_location_id'   => $this->locationDest->id,
            'status'           => \Modules\Operations\Inventory\Enums\TransferStatusEnum::Draft->value,
        ]);

        $line = $transfer->lines()->create([
            'property_id'        => $this->property->id,
            'item_id'            => $this->item->id,
            'quantity_requested' => '2.0000',
        ]);

        // Act
        $completed = $this->transferService->complete($transfer->id);

        // Verify document is completed
        $this->assertEquals(\Modules\Operations\Inventory\Enums\TransferStatusEnum::Completed, $completed->status);

        // Verify transactions created: 1 outbound and 1 inbound
        $this->assertDatabaseCount('inventory_transactions', 2);

        $txs = DB::table('inventory_transactions')->get();
        $this->assertCount(2, $txs);

        $outTx = $txs->firstWhere('transaction_type', 'transfer_out');
        $inTx = $txs->firstWhere('transaction_type', 'transfer_in');

        $this->assertNotNull($outTx);
        $this->assertNotNull($inTx);

        // Assert unit cost matches source locked state WAUC (10.0000) not legacy item cost (25.0000)
        $this->assertEquals('10.0000', (string) $outTx->unit_cost);
        $this->assertEquals('10.0000', (string) $inTx->unit_cost);

        $this->assertEquals('-20.0000', (string) $outTx->total_cost);
        $this->assertEquals('20.0000', (string) $inTx->total_cost);

        // Verify Cost Ledger Entries written
        $this->assertDatabaseCount('cost_ledger_entries', 2);

        // Verify CostAvcoState updated correctly
        $stateSrc = CostAvcoState::where('location_id', $this->locationSrc->id)->first();
        $stateDest = CostAvcoState::where('location_id', $this->locationDest->id)->first();

        $this->assertEquals('8.0000', $stateSrc->on_hand_quantity);
        $this->assertEquals('80.0000', $stateSrc->carrying_value);

        $this->assertEquals('7.0000', $stateDest->on_hand_quantity);
        $this->assertEquals('60.0000', $stateDest->carrying_value);
        $this->assertEquals('8.5714', $stateDest->weighted_average_unit_cost);
    }

    /**
     * 3. All-unenrolled path: follows legacy path unchanged.
     */
    public function test_unenrolled_path_uses_legacy_wac(): void
    {
        // No enrollment group seeded!
        $this->seedStock($this->locationSrc->id, '10.0000');
        $this->seedStock($this->locationDest->id, '5.0000');

        $transfer = InventoryTransfer::create([
            'property_id'      => $this->property->id,
            'transfer_number'  => 'XFER-002',
            'from_location_id' => $this->locationSrc->id,
            'to_location_id'   => $this->locationDest->id,
            'status'           => \Modules\Operations\Inventory\Enums\TransferStatusEnum::Draft->value,
        ]);

        $line = $transfer->lines()->create([
            'property_id'        => $this->property->id,
            'item_id'            => $this->item->id,
            'quantity_requested' => '2.0000',
        ]);

        // Act
        $completed = $this->transferService->complete($transfer->id);

        $this->assertEquals(\Modules\Operations\Inventory\Enums\TransferStatusEnum::Completed, $completed->status);

        // Verify transactions created: legacy unit cost is item's WAC (25.0000)
        $this->assertDatabaseCount('inventory_transactions', 2);

        $txs = DB::table('inventory_transactions')->get();
        $this->assertCount(2, $txs);

        $outTx = $txs->firstWhere('transaction_type', 'transfer_out');
        $inTx = $txs->firstWhere('transaction_type', 'transfer_in');

        $this->assertEquals('25.0000', (string) $outTx->unit_cost);
        $this->assertEquals('25.0000', (string) $inTx->unit_cost);

        // Verify NO Cost Ledger Entries written
        $this->assertDatabaseCount('cost_ledger_entries', 0);
    }

    /**
     * 4. Mixed enrollment authority: fails before state lock or database writes.
     */
    public function test_mixed_enrollment_fails_closed(): void
    {
        $groupId = $this->seedGroup($this->item->id);

        $otherItem = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $this->item->category_id,
            'sku'                   => 'XFER-MIXED-002',
            'name'                  => 'Unenrolled Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => '15.0000',
            'is_active'             => true,
        ]);

        $this->seedStock($this->locationSrc->id, '10.0000');
        $this->seedStock($this->locationDest->id, '5.0000');

        $transfer = InventoryTransfer::create([
            'property_id'      => $this->property->id,
            'transfer_number'  => 'XFER-003',
            'from_location_id' => $this->locationSrc->id,
            'to_location_id'   => $this->locationDest->id,
            'status'           => \Modules\Operations\Inventory\Enums\TransferStatusEnum::Draft->value,
        ]);

        // Line 1: enrolled
        $transfer->lines()->create([
            'property_id'        => $this->property->id,
            'item_id'            => $this->item->id,
            'quantity_requested' => '2.0000',
        ]);

        // Line 2: unenrolled
        $transfer->lines()->create([
            'property_id'        => $this->property->id,
            'item_id'            => $otherItem->id,
            'quantity_requested' => '1.0000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixed enrollment status detected');

        try {
            $this->transferService->complete($transfer->id);
        } finally {
            $this->assertDatabaseCount('inventory_transactions', 0);
        }
    }

    /**
     * 6. Rollback behavior: fails and rolls back both transactions and state changes.
     */
    public function test_rollback_on_failed_valuation(): void
    {
        $groupId = $this->seedGroup($this->item->id);
        $snapSrcId = $this->seedSnapshot($groupId, $this->locationSrc->id, '10.0000', '100.0000');
        $this->seedState($groupId, $snapSrcId, $this->locationSrc->id, null, null, '10.0000', '100.0000', '10.0000');

        $snapDestId = $this->seedSnapshot($groupId, $this->locationDest->id, '5.0000', '40.0000');
        // Setup sequence mismatch to force a planning failure on destination sequence validation (gap)
        $this->seedState($groupId, $snapDestId, $this->locationDest->id, 5, $this->businessDate, '5.0000', '40.0000', '8.0000');

        $this->seedStock($this->locationSrc->id, '10.0000');
        $this->seedStock($this->locationDest->id, '5.0000');

        $transfer = InventoryTransfer::create([
            'property_id'      => $this->property->id,
            'transfer_number'  => 'XFER-004',
            'from_location_id' => $this->locationSrc->id,
            'to_location_id'   => $this->locationDest->id,
            'status'           => \Modules\Operations\Inventory\Enums\TransferStatusEnum::Draft->value,
        ]);

        $transfer->lines()->create([
            'property_id'        => $this->property->id,
            'item_id'            => $this->item->id,
            'quantity_requested' => '2.0000',
        ]);

        try {
            $this->transferService->complete($transfer->id);
            $this->fail('Should have failed due to destination sequence gap.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Destination sequence gap', $e->getMessage());
        }

        // Verify Rollback: no transaction created, no cost ledger entries written, states unchanged
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cost_ledger_entries', 0);

        $stateSrc = CostAvcoState::where('location_id', $this->locationSrc->id)->first();
        $this->assertNull($stateSrc->last_valuation_sequence);
        $this->assertEquals('10.0000', $stateSrc->on_hand_quantity);
    }

    /**
     * 8. No production service outside the one resolved transfer service invokes ControlledTransferValuationInvocationService.
     */
    public function test_no_production_service_references_invocation_service(): void
    {
        $modulePath = base_path('Modules');
        $callers = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, 'ControlledTransferValuationInvocationService.php') ||
                str_contains($path, 'TransferService.php')) {
                continue;
            }
            if (str_contains(file_get_contents($path), 'ControlledTransferValuationInvocationService')) {
                $callers[] = $path;
            }
        }

        $this->assertEmpty($callers, 'Invocation service has unauthorized production callers!');
    }
}
