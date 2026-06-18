<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Enums\CountScopeEnum;
use Modules\Operations\Inventory\Enums\CountStatusEnum;
use Modules\Operations\Inventory\Enums\ReasonCodeEnum;
use Modules\Operations\Inventory\Enums\SessionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\StockCountSession;
use Modules\Operations\Inventory\Services\StockCountLineService;
use Modules\Operations\Inventory\Services\StockCountSessionService;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Tests\TestCase;

class StockOpnameFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $location;
    private StockCountSessionService $sessionService;
    private StockCountLineService $lineService;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->sessionService = app(StockCountSessionService::class);
        $this->lineService    = app(StockCountLineService::class);

        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);

        $this->item = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $invCategory->id,
            'sku'                   => 'ITM-OPN-1',
            'name'                  => 'Opname Test Item',
            'inventory_type'        => 'goods',
            'is_active'             => true,
            'reorder_point'         => 10,
            'weighted_average_cost' => 10.00,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Main Store',
            'type'        => 'internal',
        ]);

        InventoryStock::create([
            'property_id'       => $this->property->id,
            'item_id'           => $this->item->id,
            'location_id'       => $this->location->id,
            'physical_quantity' => 100,
            'reserved_quantity' => 0,
        ]);
    }

    public function test_stock_opname_lifecycle()
    {
        $user = \Modules\Foundation\User\Models\User::first();
        $user->properties()->syncWithoutDetaching([$this->property->id]);
        $this->actingAs($user);

        // 1. Create Session
        $session = $this->sessionService->create([
            'property_id'    => $this->property->id,
            'session_number' => 'OPN-001',
            'type'           => SessionTypeEnum::FULL_COUNT->value,
            'scope'          => CountScopeEnum::PROPERTY->value,
            'location_id'    => $this->location->id,
            'created_by'     => \Modules\Foundation\User\Models\User::first()->id,
        ]);

        $this->assertEquals(CountStatusEnum::DRAFT, $session->status);

        // 2. Add Item
        $this->lineService->addItems($session->id, [$this->item->id]);
        $this->assertCount(1, $session->lines);

        // 3. Start Count (Snapshot Strategy)
        $this->sessionService->startCount($session->id);
        $session->refresh();
        $this->assertEquals(CountStatusEnum::IN_PROGRESS, $session->status);
        $line = $session->lines->first();
        $this->assertEquals(100, (float) $line->expected_quantity_snapshot);

        // 4. Update Count (Negative Variance)
        $this->lineService->updateCount($line->id, 90, ReasonCodeEnum::LOSS->value);
        $line->refresh();
        $this->assertEquals(90, (float) $line->counted_quantity);
        $this->assertEquals(-10, (float) $line->variance_quantity);
        $this->assertEquals(-100.00, $line->variance_cost); // Computed accessory check: -10 * 10.00

        // 5. Submit Count (Session Lock & Staleness Check)
        // First we need a workflow setup
        $workflow = ApprovalWorkflow::create([
            'property_id' => $this->property->id,
            'approvable_type' => StockCountSession::class,
            'name' => 'Stock Count Approval',
            'is_active' => true,
        ]);
        
        ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Inventory Manager',
            'required_approvals' => 1,
        ]);

        $this->sessionService->submit($session->id);
        $session->refresh();
        $this->assertEquals(CountStatusEnum::SUBMITTED, $session->status);

        // 6. Approve via Engine
        $approvalEngine = app(ApprovalEngineService::class);
        $approvalRequest = $session->approvalRequests()->first();
        $this->assertNotNull($approvalRequest);
        
        $approvalEngine->approve($approvalRequest, $user->id, 'Stock counts are correct.');
        
        $session->refresh();
        $this->assertEquals(CountStatusEnum::APPROVED, $session->status);
    }

    public function test_staleness_detection()
    {
        $user = \Modules\Foundation\User\Models\User::first();
        $user->properties()->syncWithoutDetaching([$this->property->id]);
        $this->actingAs($user);

        $session = $this->sessionService->create([
            'property_id'    => $this->property->id,
            'session_number' => 'OPN-002',
            'type'           => SessionTypeEnum::SPOT_COUNT->value,
            'scope'          => CountScopeEnum::LOCATION->value,
            'location_id'    => $this->location->id,
            'created_by'     => \Modules\Foundation\User\Models\User::first()->id,
        ]);

        $this->lineService->addItems($session->id, [$this->item->id]);
        $this->sessionService->startCount($session->id);

        $line = $session->lines->first();
        $this->lineService->updateCount($line->id, 95, ReasonCodeEnum::LOSS->value);

        // Simulate operational movement changing physical stock while IN_PROGRESS
        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $stock->update(['physical_quantity' => 98]);

        // Submit should trigger Staleness Exception
        $this->expectException(ValidationException::class);
        $this->sessionService->submit($session->id);
    }
}
