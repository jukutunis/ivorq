<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Shared\Services\CurrentPropertyService;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Services\IssueService;
use Illuminate\Validation\ValidationException;
use Shared\Exceptions\BusinessLogicException;

class ControlledInventoryIssuePostingTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location;
    private IssueService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->user = User::first();
        $this->actingAs($this->user);

        // Open Business Date and Financial Period
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            [
                'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_at' => now(),
                'opened_by' => $this->user->id
            ]
        );

        FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            [
                'status' => \Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum::Open,
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth()
            ]
        );

        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'General'
        ]);

        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-ISS-001',
            'name' => 'Test Issue Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active' => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);

        // Seed initial physical quantity of 100
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 100,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        $this->service = app(IssueService::class);
    }

    public function test_single_line_issue_success(): void
    {
        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-SL-001',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 15,
        ]);

        $this->service->post($issue->id);

        $this->assertEquals(IssueStatusEnum::Posted, $issue->fresh()->status);
        $this->assertEquals($this->user->id, $issue->fresh()->posted_by);

        $txs = InventoryTransaction::where('source_document_id', $issue->id)->get();
        $this->assertCount(1, $txs);

        $tx = $txs->first();
        $this->assertEquals('inventory_issue', $tx->source_document_type);
        $this->assertEquals('inventory_issue_line', $tx->source_line_type);
        $this->assertEquals(-15, $tx->quantity_change);
        $this->assertEquals(10.00, (float) $tx->unit_cost);
        $this->assertEquals(-150.00, (float) $tx->total_cost);
        $this->assertEquals($this->user->id, $tx->posted_by);

        $stock = InventoryStock::where('item_id', $this->item->id)->first();
        $this->assertEquals(85, $stock->physical_quantity);
    }

    public function test_multi_line_atomic_success_with_sorting(): void
    {
        $item2 = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $this->item->category_id,
            'sku' => 'ITM-ISS-002',
            'name' => 'Second Issue Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 20.00,
            'is_active' => true,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $item2->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 50,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-ML-001',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        // Add line for item2 first, then item1 (unsorted)
        $line2 = InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $item2->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
        ]);

        $line1 = InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
        ]);

        $this->service->post($issue->id);

        $this->assertEquals(IssueStatusEnum::Posted, $issue->fresh()->status);

        $txs = InventoryTransaction::where('source_document_id', $issue->id)
            ->orderBy('item_id', 'asc')
            ->get();
        $this->assertCount(2, $txs);

        $this->assertEquals($this->item->id, $txs[0]->item_id);
        $this->assertEquals(-5, $txs[0]->quantity_change);

        $this->assertEquals($item2->id, $txs[1]->item_id);
        $this->assertEquals(-10, $txs[1]->quantity_change);

        $this->assertEquals(95, InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity);
        $this->assertEquals(40, InventoryStock::where('item_id', $item2->id)->first()->physical_quantity);
    }

    public function test_replay_idempotency(): void
    {
        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-IDEM-001',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 20,
        ]);

        $this->service->post($issue->id);

        $txCountBefore = InventoryTransaction::where('source_document_id', $issue->id)->count();
        $stockBefore = InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity;

        // Force transition status back to Draft to simulate re-posting
        $issue = $issue->fresh();
        $issue->update(['status' => IssueStatusEnum::Draft->value]);

        $this->service->post($issue->id);

        $txCountAfter = InventoryTransaction::where('source_document_id', $issue->id)->count();
        $stockAfter = InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity;

        $this->assertEquals($txCountBefore, $txCountAfter);
        $this->assertEquals($stockBefore, $stockAfter);
        $this->assertEquals(IssueStatusEnum::Posted, $issue->fresh()->status);
    }

    public function test_insufficient_stock_fails_closed(): void
    {
        $item2 = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $this->item->category_id,
            'sku' => 'ITM-ISS-003',
            'name' => 'Low Stock Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 20.00,
            'is_active' => true,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $item2->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 5, // low stock
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-FAIL-001',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        // Sorted order: item1 (sku: ITM-ISS-001), item2 (sku: ITM-ISS-003)
        // First line is valid (10 units vs 100 available)
        // Second line is invalid (10 units vs 5 available)
        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $item2->id,
            'location_id' => $this->location->id,
            'quantity' => 10, // exceeds available stock
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Negative stock is not allowed/');

        try {
            $this->service->post($issue->id);
        } finally {
            // Verify total rollback
            $this->assertEquals(IssueStatusEnum::Draft, $issue->fresh()->status);
            $this->assertEquals(100, InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity);
            $this->assertEquals(5, InventoryStock::where('item_id', $item2->id)->first()->physical_quantity);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $issue->id)->get());
        }
    }

    public function test_closed_business_date_rejects_issue(): void
    {
        // Close the business date
        PropertyBusinessDate::where('property_id', $this->property->id)
            ->update([
                'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Closed,
                'is_open' => null
            ]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-BD-CLOSED',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
        ]);

        $this->expectException(\Throwable::class);

        try {
            $this->service->post($issue->id);
        } finally {
            $this->assertEquals(IssueStatusEnum::Draft, $issue->fresh()->status);
            $this->assertEquals(100, InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $issue->id)->get());
        }
    }

    public function test_closed_financial_period_rejects_issue(): void
    {
        // Close the financial period
        FinancialPeriod::where('property_id', $this->property->id)
            ->update(['status' => \Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum::Closed]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-FP-CLOSED',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
        ]);

        $this->expectException(\Throwable::class);

        try {
            $this->service->post($issue->id);
        } finally {
            $this->assertEquals(IssueStatusEnum::Draft, $issue->fresh()->status);
            $this->assertEquals(100, InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $issue->id)->get());
        }
    }

    public function test_null_wac_fails_closed(): void
    {
        $mockItem = $this->item->replicate();
        $mockItem->id = $this->item->id;
        $mockItem->weighted_average_cost = null;

        // Use createStub to eliminate PHPUnit notice about mock without expectations
        $mockRepo = $this->createStub(\Modules\Operations\Inventory\Repositories\InventoryItemRepository::class);
        $mockRepo->method('find')->willReturn($mockItem);
        $this->app->instance(\Modules\Operations\Inventory\Repositories\InventoryItemRepository::class, $mockRepo);

        // Resolve service after mocking the repository dependency
        $service = app(IssueService::class);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-NULLWAC',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/does not have a valid weighted average cost/');

        try {
            $service->post($issue->id);
        } finally {
            $this->assertEquals(IssueStatusEnum::Draft, $issue->fresh()->status);
            $this->assertEquals(100, InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $issue->id)->get());
        }
    }

    public function test_zero_wac_compatibility(): void
    {
        $itemZeroWac = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $this->item->category_id,
            'sku' => 'ITM-ISS-ZEROWAC',
            'name' => 'Zero WAC Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0.00,
            'is_active' => true,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $itemZeroWac->id,
            'location_id' => $this->location->id,
            'physical_quantity' => 10,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-ZEROWAC',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $itemZeroWac->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
        ]);

        $this->service->post($issue->id);

        $this->assertEquals(IssueStatusEnum::Posted, $issue->fresh()->status);
        $this->assertEquals(5, InventoryStock::where('item_id', $itemZeroWac->id)->first()->physical_quantity);

        $txs = InventoryTransaction::where('source_document_id', $issue->id)->get();
        $this->assertCount(1, $txs);
        $this->assertEquals(0.00, (float) $txs->first()->unit_cost);
        $this->assertEquals(0.00, (float) $txs->first()->total_cost);
    }

    public function test_actor_mismatch_rejection(): void
    {
        $actorB = User::create([
            'name' => 'Actor B',
            'email' => 'actorb@ivorq.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-ACTOR-MISMATCH',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
        ]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessageMatches('/does not match the authenticated posting operator/');

        try {
            $this->service->post($issue->id, $actorB->id);
        } finally {
            $this->assertEquals(IssueStatusEnum::Draft, $issue->fresh()->status);
            $this->assertEquals(100, InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $issue->id)->get());
        }
    }

    public function test_missing_authenticated_actor_rejection(): void
    {
        auth()->logout();

        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'ISS-NO-ACTOR',
            'status' => IssueStatusEnum::Draft->value,
            'department_id' => \Modules\Foundation\Department\Models\Department::first()?->id ?? null,
        ]);

        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
        ]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessageMatches('/Authenticated posting operator is required/');

        try {
            $this->service->post($issue->id, null);
        } finally {
            $this->assertEquals(IssueStatusEnum::Draft, $issue->fresh()->status);
            $this->assertEquals(100, InventoryStock::where('item_id', $this->item->id)->first()->physical_quantity);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $issue->id)->get());
        }
    }
}
