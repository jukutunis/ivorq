<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Contracts\AuthoritativeInventoryCostPort;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Operations\Inventory\Services\IssueService;
use Modules\Operations\Inventory\Services\ReceiptService;
use Modules\Operations\Inventory\Services\TransferService;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Modules\Operations\Receiving\Services\InventoryReceiptIntegrationService;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class InventoryProducerCostDeliveryModeGateTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $actor;

    private InventoryItem $item;

    private InventoryLocation $primaryLocation;

    private InventoryLocation $secondaryLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();
        $this->actingAs($this->actor);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        DB::table('property_business_dates')->where('property_id', $this->property->id)->update([
            'status' => PropertyBusinessDateStatusEnum::Closed->value,
            'is_open' => null,
            'closed_at' => now(),
            'closed_by' => $this->actor->id,
        ]);
        PropertyBusinessDate::create([
            'property_id' => $this->property->id,
            'business_date' => now()->toDateString(),
            'timezone_snapshot' => 'UTC',
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_at' => now(),
            'opened_by' => $this->actor->id,
        ]);
        FinancialPeriod::updateOrCreate([
            'property_id' => $this->property->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
        ], [
            'status' => FinancialPeriodStatusEnum::Open,
            'start_date' => now()->startOfMonth(),
            'end_date' => now()->endOfMonth(),
        ]);

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'P01F Producer Gate',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'P01F-PRODUCER-'.Str::random(8),
            'name' => 'P01F Producer Gate Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => '10.0000',
            'is_active' => true,
        ]);
        $this->primaryLocation = $this->makeLocation('Primary');
        $this->secondaryLocation = $this->makeLocation('Secondary');
        $this->makeStock($this->primaryLocation, '100.0000');
        $this->makeStock($this->secondaryLocation, '0.0000');
    }

    public function test_each_enrolled_producer_invokes_sync_only_for_synchronous_ownership(): void
    {
        foreach ([
            'receipt' => 1,
            'issue' => 1,
            'adjustment' => 1,
            'transfer' => 2,
            'receiving' => 1,
        ] as $producer => $sourceCount) {
            $sync = $this->bindMode(CostDeliveryPostingDecision::SYNCHRONOUS);
            $documentId = $this->runProducer($producer);

            $expectedCall = $producer === 'receiving' ? 'receipt' : $producer;
            $this->assertSame([$expectedCall], $sync->calls, "{$producer} must invoke its synchronous port exactly once.");
            $this->assertSame(
                $sourceCount,
                InventoryTransaction::where('source_document_id', $documentId)
                    ->where('cost_delivery_mode', CostDeliveryPostingDecision::SYNCHRONOUS)
                    ->count(),
            );

            $sync = $this->bindMode(CostDeliveryPostingDecision::DEFERRED);
            $documentId = $this->runProducer($producer);

            $this->assertSame([], $sync->calls, "{$producer} must bypass the synchronous port in deferred mode.");
            $this->assertSame(
                $sourceCount,
                InventoryTransaction::where('source_document_id', $documentId)
                    ->where('cost_delivery_mode', CostDeliveryPostingDecision::DEFERRED)
                    ->count(),
            );
        }
    }

    private function bindMode(string $mode): RecordingSynchronousCostValuationPort
    {
        $this->app->instance(CostDeliveryModePort::class, new ProducerGateCostDeliveryModePort($mode));
        $this->app->instance(AuthoritativeInventoryCostPort::class, new ProducerGateAuthoritativeCostPort);
        $sync = new RecordingSynchronousCostValuationPort;
        $this->app->instance(SynchronousCostValuationPort::class, $sync);

        return $sync;
    }

    private function runProducer(string $producer): string
    {
        return match ($producer) {
            'receipt' => $this->runReceipt(),
            'issue' => $this->runIssue(),
            'adjustment' => $this->runAdjustment(),
            'transfer' => $this->runTransfer(),
            'receiving' => $this->runReceiving(),
        };
    }

    private function runReceipt(): string
    {
        $receipt = InventoryReceipt::create([
            'property_id' => $this->property->id,
            'receipt_number' => 'P01F-RCP-'.Str::random(8),
            'supplier_name' => 'P01F Producer Gate',
            'status' => ReceiptStatusEnum::Draft,
        ]);
        InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id' => $receipt->id,
            'item_id' => $this->item->id,
            'location_id' => $this->primaryLocation->id,
            'quantity' => '1.0000',
            'unit_cost' => '12.0000',
            'line_total' => '12.0000',
        ]);

        app(ReceiptService::class)->post($receipt->id, $this->actor->id);

        return $receipt->id;
    }

    private function runIssue(): string
    {
        $issue = InventoryIssue::create([
            'property_id' => $this->property->id,
            'issue_number' => 'P01F-ISS-'.Str::random(8),
            'status' => IssueStatusEnum::Draft,
        ]);
        InventoryIssueLine::create([
            'property_id' => $this->property->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'location_id' => $this->primaryLocation->id,
            'quantity' => '1.0000',
        ]);

        app(IssueService::class)->post($issue->id, $this->actor->id);

        return $issue->id;
    }

    private function runAdjustment(): string
    {
        $quantity = (string) InventoryStock::where('item_id', $this->item->id)
            ->where('location_id', $this->primaryLocation->id)
            ->value('physical_quantity');
        $adjustment = InventoryAdjustment::create([
            'property_id' => $this->property->id,
            'adjustment_number' => 'P01F-ADJ-'.Str::random(8),
            'location_id' => $this->primaryLocation->id,
            'status' => AdjustmentStatusEnum::Submitted,
        ]);
        InventoryAdjustmentLine::create([
            'property_id' => $this->property->id,
            'adjustment_id' => $adjustment->id,
            'item_id' => $this->item->id,
            'quantity_system' => $quantity,
            'quantity_actual' => bcadd($quantity, '1.0000', 4),
            'quantity_variance' => '1.0000',
            'unit_cost' => '12.0000',
        ]);

        app(AdjustmentService::class)->approve($adjustment->id, $this->actor->id);

        return $adjustment->id;
    }

    private function runTransfer(): string
    {
        $transfer = InventoryTransfer::create([
            'property_id' => $this->property->id,
            'transfer_number' => 'P01F-TRF-'.Str::random(8),
            'from_location_id' => $this->primaryLocation->id,
            'to_location_id' => $this->secondaryLocation->id,
            'status' => TransferStatusEnum::Submitted,
        ]);
        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item->id,
            'quantity_requested' => '1.0000',
        ]);

        app(TransferService::class)->complete($transfer->id, $this->actor->id);

        return $transfer->id;
    }

    private function runReceiving(): string
    {
        $vendorCategory = VendorCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'category_code' => 'P01F-GATE',
        ], ['name' => 'P01F Producer Gate']);
        $vendor = Vendor::firstOrCreate([
            'property_id' => $this->property->id,
            'vendor_code' => 'P01F-GATE',
        ], [
            'vendor_category_id' => $vendorCategory->id,
            'name' => 'P01F Producer Gate',
        ]);
        $document = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $vendor->id,
            'grn_number' => 'P01F-GRN-'.Str::random(8),
            'status' => 'submitted',
        ]);
        ReceivingLine::create([
            'receiving_document_id' => $document->id,
            'inventory_item_id' => $this->item->id,
            'destination_location_id' => $this->primaryLocation->id,
            'description' => 'P01F producer gate line',
            'received_quantity' => '1.0000',
            'unit_cost' => '12.0000',
            'line_total' => '12.0000',
        ]);

        app(InventoryReceiptIntegrationService::class)->syncToInventory($document, $this->actor->id);

        return $document->id;
    }

    private function makeLocation(string $name): InventoryLocation
    {
        return InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => "P01F {$name} ".Str::random(8),
            'type' => 'internal',
        ]);
    }

    private function makeStock(InventoryLocation $location, string $quantity): void
    {
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $location->id,
            'physical_quantity' => $quantity,
            'status' => bccomp($quantity, '0.0000', 4) > 0 ? ItemStatusEnum::InStock : ItemStatusEnum::OutOfStock,
        ]);
    }
}

final class ProducerGateCostDeliveryModePort implements CostDeliveryModePort
{
    private string $ownershipId;

    private string $cutoverId;

    public function __construct(private readonly string $mode)
    {
        $this->ownershipId = (string) Str::ulid();
        $this->cutoverId = (string) Str::ulid();
    }

    public function isEnrolled(string $propertyId, string $itemId): bool
    {
        return true;
    }

    public function lockForDocumentMutation(string $propertyId, string $itemId): void {}

    public function resolveForPosting(
        string $propertyId,
        string $itemId,
        string $locationId,
    ): CostDeliveryPostingDecision {
        $scope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";

        return $this->mode === CostDeliveryPostingDecision::SYNCHRONOUS
            ? CostDeliveryPostingDecision::synchronous($propertyId, $itemId, $locationId, $scope, $this->ownershipId, 1)
            : CostDeliveryPostingDecision::deferred(
                $propertyId,
                $itemId,
                $locationId,
                $scope,
                $this->ownershipId,
                2,
                $this->cutoverId,
                0,
                1,
            );
    }
}

final class ProducerGateAuthoritativeCostPort implements AuthoritativeInventoryCostPort
{
    public function resolveUnitCostForPosting(CostDeliveryPostingDecision $prelockedDecision): string
    {
        return '10.0000';
    }
}

final class RecordingSynchronousCostValuationPort implements SynchronousCostValuationPort
{
    /** @var list<string> */
    public array $calls = [];

    public function applyReceipt(string $sourceInventoryTransactionId): string
    {
        $this->calls[] = 'receipt';

        return (string) Str::ulid();
    }

    public function applyIssue(string $sourceInventoryTransactionId): string
    {
        $this->calls[] = 'issue';

        return (string) Str::ulid();
    }

    public function applyAdjustment(string $sourceInventoryTransactionId): string
    {
        $this->calls[] = 'adjustment';

        return (string) Str::ulid();
    }

    public function applyTransfer(
        string $outboundInventoryTransactionId,
        string $inboundInventoryTransactionId,
    ): array {
        $this->calls[] = 'transfer';

        return ['outbound' => (string) Str::ulid(), 'inbound' => (string) Str::ulid()];
    }

    public function applyReversal(
        string $reversalInventoryTransactionId,
        string $originalInventoryTransactionId,
        string $reversalReason,
        string $approvalReference,
    ): string {
        $this->calls[] = 'reversal';

        return (string) Str::ulid();
    }
}
