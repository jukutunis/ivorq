<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Repositories\InventoryStockBalanceRepository;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Operations\Inventory\Services\StockMovementService;
use Tests\Feature\Operations\Concerns\CreatesInventoryData;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;
use Mockery;

class InventoryConcurrencyTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData, CreatesInventoryData;

    public function test_stock_movement_uses_find_or_create_locked_to_prevent_race_conditions(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit, ['is_active' => true]);
        $location = $this->makeInventoryLocation($property);

        // Spy on the balance repository to ensure the lock method is called
        $balanceRepoSpy = Mockery::spy(app(InventoryStockBalanceRepository::class));
        $this->app->instance(InventoryStockBalanceRepository::class, $balanceRepoSpy);

        $service = app(StockMovementService::class);

        DB::transaction(function () use ($service, $property, $item, $location, $admin) {
            $service->move(
                $property->id,
                $item->id,
                $location->id,
                '10',
                TransactionTypeEnum::AdjustmentIn,
                '15.50',
                null,
                'Test Concurrency',
                $admin->id
            );
        });

        // Verify the explicit lock method was called to prevent TOCTOU race conditions
        $balanceRepoSpy->shouldHaveReceived('findOrCreateLocked')
            ->once()
            ->with($item->id, $location->id, $property->id);
    }

    public function test_adjustment_approval_locks_balance_for_update_to_prevent_staleness(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);

        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit, ['is_active' => true]);
        $location = $this->makeInventoryLocation($property);
        
        // Initial balance
        app(StockMovementService::class)->move(
            $property->id, $item->id, $location->id, '50', TransactionTypeEnum::PurchaseReceipt, '10'
        );

        $adjustment = $this->makeInventoryAdjustment($property, $location, [
            'adjustment_number' => 'ADJ-001',
            'status' => AdjustmentStatusEnum::Submitted->value,
            'submitted_at' => now(),
            'submitted_by' => $admin->id,
        ]);

        InventoryAdjustmentLine::create([
            'adjustment_id' => $adjustment->id,
            'item_id' => $item->id,
            'quantity_system' => '50', // Expecting 50
            'quantity_actual' => '45',
            'quantity_variance' => '-5',
            'unit_cost' => '10',
        ]);

        $balanceRepoSpy = Mockery::spy(app(InventoryStockBalanceRepository::class));
        // Mock the lockForUpdate return to simulate current state
        $mockedBalance = new \Modules\Operations\Inventory\Models\InventoryStockBalance(['quantity' => '50']);
        $balanceRepoSpy->shouldReceive('lockForUpdate')
            ->with($item->id, $location->id)
            ->andReturn($mockedBalance);
            
        $this->app->instance(InventoryStockBalanceRepository::class, $balanceRepoSpy);

        $service = app(AdjustmentService::class);
        $service->approve($adjustment->id, $admin->id);

        // Verify that the row was locked for update during the approval transaction
        $balanceRepoSpy->shouldHaveReceived('lockForUpdate')
            ->once()
            ->with($item->id, $location->id);
    }
}
