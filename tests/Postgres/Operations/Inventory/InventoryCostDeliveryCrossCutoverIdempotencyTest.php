<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use RuntimeException;
use Tests\PostgresTestCase;

final class InventoryCostDeliveryCrossCutoverIdempotencyTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_exact_synchronous_source_retry_does_not_resolve_current_deferred_ownership(): void
    {
        $property = Property::where('currency', 'USD')->firstOrFail();
        $actor = User::firstOrFail();
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $property->id, 'business_date' => now()->toDateString()],
            ['status' => PropertyBusinessDateStatusEnum::Open, 'is_open' => true,
                'opened_at' => now(), 'opened_by' => $actor->id],
        );
        FinancialPeriod::updateOrCreate(
            ['property_id' => $property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            ['status' => FinancialPeriodStatusEnum::Open],
        );
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $property->id, 'name' => 'P01F Replay '.Str::random(6),
        ]);
        $item = InventoryItem::create([
            'property_id' => $property->id, 'category_id' => $category->id,
            'sku' => 'P01F-R-'.Str::random(8), 'name' => 'P01F Replay',
            'inventory_type' => 'goods', 'weighted_average_cost' => 10, 'is_active' => true,
        ]);
        $location = InventoryLocation::create([
            'property_id' => $property->id, 'name' => 'P01F Replay '.Str::random(8), 'type' => 'internal',
        ]);
        InventoryStock::create([
            'property_id' => $property->id, 'item_id' => $item->id, 'location_id' => $location->id,
            'physical_quantity' => '10.0000', 'status' => ItemStatusEnum::InStock,
        ]);
        $intent = new InventoryLedgerPostingIntent(
            propertyId: $property->id, itemId: $item->id, locationId: $location->id,
            businessDate: now()->toDateString(), occurredAt: now(),
            sourceDocumentType: 'inventory_adjustment', sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'inventory_adjustment_line', sourceLineId: (string) Str::ulid(),
            movementRole: TransactionTypeEnum::AdjustmentIn->value,
            idempotencyKey: 'p01f-replay-'.Str::random(12),
            transactionType: TransactionTypeEnum::AdjustmentIn,
            quantityChange: '1.0000', unitCost: '10.0000', totalCost: '10.0000',
        );
        $ownershipId = (string) Str::ulid();
        $initialPort = new P01FReplayModePort(CostDeliveryPostingDecision::synchronous(
            $property->id, $item->id, $location->id,
            "property:{$property->id}:location:{$location->id}:item:{$item->id}",
            $ownershipId, 1,
        ));
        app()->instance(CostDeliveryModePort::class, $initialPort);
        $first = app(InventoryPostingControlCoordinator::class)->post($intent, $actor->id);

        $rejectingPort = new P01FReplayModePort(null, true);
        app()->instance(CostDeliveryModePort::class, $rejectingPort);
        $replayed = app(InventoryPostingControlCoordinator::class)->post($intent, $actor->id);

        $this->assertSame($first->id, $replayed->id);
        $this->assertSame($ownershipId, $replayed->cost_delivery_ownership_id);
        $this->assertSame(0, $rejectingPort->calls);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory_valuation_sequences', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }
}

final class P01FReplayModePort implements CostDeliveryModePort
{
    public int $calls = 0;

    public function __construct(
        private readonly ?CostDeliveryPostingDecision $decision,
        private readonly bool $reject = false,
    ) {}

    public function isEnrolled(string $propertyId, string $itemId): bool
    {
        return true;
    }

    public function lockForDocumentMutation(string $propertyId, string $itemId): void {}

    public function resolveForPosting(string $propertyId, string $itemId, string $locationId): CostDeliveryPostingDecision
    {
        $this->calls++;
        if ($this->reject) {
            throw new RuntimeException('Current ownership must not be resolved for exact replay.');
        }

        return $this->decision;
    }
}
