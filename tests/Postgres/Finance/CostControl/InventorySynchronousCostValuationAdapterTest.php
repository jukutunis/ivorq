<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Services\CostIssuePostingEngine;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Tests\PostgresTestCase;

final class InventorySynchronousCostValuationAdapterTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_receipt_port_applies_exact_source_once(): void
    {
        [$property, $item, $locations, $ownershipId, $actorId, $periodId] = $this->fixture(1);
        $source = $this->source(
            $property->id, $item->id, $locations[0]->id, $ownershipId, $actorId, $periodId,
            TransactionTypeEnum::PurchaseReceipt, 1, '2.0000', '5.0000', '10.0000',
        );

        $ledgerId = DB::transaction(fn () => app(SynchronousCostValuationPort::class)->applyReceipt($source->id));

        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $ledgerId,
            'source_inventory_transaction_id' => $source->id,
            'entry_type' => 'receipt',
        ]);
        $this->assertState($property->id, $item->id, $locations[0]->id, 1, '12.0000', '110.0000');
    }

    public function test_issue_port_applies_exact_source_and_invokes_gl_handoff(): void
    {
        [$property, $item, $locations, $ownershipId, $actorId, $periodId] = $this->fixture(1);
        $source = $this->source(
            $property->id, $item->id, $locations[0]->id, $ownershipId, $actorId, $periodId,
            TransactionTypeEnum::Issue, 1, '-2.0000', '10.0000', '-20.0000',
        );
        $this->mock(CostIssuePostingEngine::class, function ($mock): void {
            $mock->shouldReceive('process')->once()->andReturn(new JournalCandidate);
        });

        $ledgerId = DB::transaction(fn () => app(SynchronousCostValuationPort::class)->applyIssue($source->id));

        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $ledgerId,
            'source_inventory_transaction_id' => $source->id,
            'entry_type' => 'issue',
        ]);
        $this->assertState($property->id, $item->id, $locations[0]->id, 1, '8.0000', '80.0000');
    }

    public function test_adjustment_port_applies_exact_source(): void
    {
        [$property, $item, $locations, $ownershipId, $actorId, $periodId] = $this->fixture(1);
        $source = $this->source(
            $property->id, $item->id, $locations[0]->id, $ownershipId, $actorId, $periodId,
            TransactionTypeEnum::AdjustmentIn, 1, '2.0000', '5.0000', '10.0000',
        );

        $ledgerId = DB::transaction(fn () => app(SynchronousCostValuationPort::class)->applyAdjustment($source->id));

        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $ledgerId,
            'source_inventory_transaction_id' => $source->id,
            'entry_type' => 'adjustment',
        ]);
        $this->assertState($property->id, $item->id, $locations[0]->id, 1, '12.0000', '110.0000');
    }

    public function test_transfer_port_applies_the_exact_pair_in_canonical_scope_order(): void
    {
        [$property, $item, $locations, $ownershipId, $actorId, $periodId] = $this->fixture(2);
        $documentId = (string) Str::ulid();
        $lineId = (string) Str::ulid();
        $outbound = $this->source(
            $property->id, $item->id, $locations[0]->id, $ownershipId, $actorId, $periodId,
            TransactionTypeEnum::TransferOut, 1, '-2.0000', '10.0000', '-20.0000',
            $documentId, $lineId,
        );
        $inbound = $this->source(
            $property->id, $item->id, $locations[1]->id, $ownershipId, $actorId, $periodId,
            TransactionTypeEnum::TransferIn, 1, '2.0000', '10.0000', '20.0000',
            $documentId, $lineId,
        );

        $ledgerIds = DB::transaction(fn () => app(SynchronousCostValuationPort::class)
            ->applyTransfer($outbound->id, $inbound->id));

        $this->assertCount(2, $ledgerIds);
        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $ledgerIds['outbound'],
            'source_inventory_transaction_id' => $outbound->id,
            'entry_type' => 'transfer',
        ]);
        $this->assertDatabaseHas('cost_ledger_entries', [
            'id' => $ledgerIds['inbound'],
            'source_inventory_transaction_id' => $inbound->id,
            'entry_type' => 'transfer',
        ]);
        $this->assertState($property->id, $item->id, $locations[0]->id, 1, '8.0000', '80.0000');
        $this->assertState($property->id, $item->id, $locations[1]->id, 1, '12.0000', '120.0000');
    }

    private function fixture(int $locationCount): array
    {
        $property = Property::where('currency', 'USD')->firstOrFail();
        $requester = User::firstOrFail();
        $approver = User::whereKeyNot($requester->id)->firstOrFail();
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $property->id,
            'name' => 'P01F Adapter '.Str::random(6),
        ]);
        $item = InventoryItem::create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'sku' => 'P01F-A-'.Str::random(8),
            'name' => 'P01F Adapter Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10,
            'is_active' => true,
        ]);
        $period = FinancialPeriod::updateOrCreate(
            ['property_id' => $property->id, 'period_year' => 2026, 'period_month' => 9],
            ['status' => FinancialPeriodStatusEnum::Open],
        );
        $locations = [];
        $snapshots = [];
        for ($index = 0; $index < $locationCount; $index++) {
            $location = InventoryLocation::create([
                'property_id' => $property->id,
                'name' => 'P01F Adapter '.Str::random(8),
                'type' => 'internal',
            ]);
            $locations[] = $location;
            $snapshots[] = [
                'location_id' => $location->id,
                'valuation_scope' => "property:{$property->id}:location:{$location->id}:item:{$item->id}",
                'opening_quantity' => '10.0000',
                'opening_carrying_value' => '100.0000',
                'currency_code' => 'USD',
                'business_date' => '2026-09-01',
                'financial_period_id' => $period->id,
                'source_reference' => 'P01F-ADAPTER',
                'evidence_timestamp' => now(),
            ];
        }
        $repository = app(CostAuthorityEnrollmentRepository::class);
        $group = $repository->createDraft(
            ['property_id' => $property->id, 'item_id' => $item->id],
            $snapshots,
        );
        DB::transaction(fn () => $repository->approve($group->id, $approver->id, now()));
        app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup($group->id, $requester->id);
        $ownership = app(CostAuthorityEnrollmentActivationService::class)->activate($group->id, $requester->id);

        return [$property, $item, $locations, $ownership->id, $requester->id, $period->id];
    }

    private function source(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $ownershipId,
        string $actorId,
        string $periodId,
        TransactionTypeEnum $type,
        int $sequence,
        string $quantity,
        string $unitCost,
        string $totalCost,
        ?string $documentId = null,
        ?string $lineId = null,
    ): InventoryTransaction {
        $documentId ??= (string) Str::ulid();
        $lineId ??= (string) Str::ulid();
        $documentType = $type === TransactionTypeEnum::TransferOut || $type === TransactionTypeEnum::TransferIn
            ? 'inventory_transfer'
            : match ($type) {
                TransactionTypeEnum::PurchaseReceipt => 'inventory_receipt',
                TransactionTypeEnum::Issue => 'inventory_issue',
                default => 'inventory_adjustment',
            };
        $lineType = $documentType.'_line';
        DB::table('inventory_valuation_sequences')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'location_id' => $locationId,
            'item_id' => $itemId,
            'last_sequence' => $sequence,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryTransaction::create([
            'property_id' => $propertyId,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'currency_code' => 'USD',
            'financial_period_id' => $periodId,
            'valuation_scope' => "property:{$propertyId}:location:{$locationId}:item:{$itemId}",
            'valuation_sequence' => $sequence,
            'valuation_approval_status' => 'approved',
            'valuation_approval_reference' => "{$documentType}:{$documentId}:approved",
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_ownership_id' => $ownershipId,
            'cost_delivery_ownership_version' => 1,
            'cost_delivery_cutover_id' => null,
            'business_date' => '2026-09-01',
            'occurred_at' => '2026-09-01 10:00:00+00',
            'source_document_type' => $documentType,
            'source_document_id' => $documentId,
            'source_line_type' => $lineType,
            'source_line_id' => $lineId,
            'movement_role' => $type->value,
            'idempotency_key' => 'p01f-adapter-'.Str::random(12),
            'transaction_type' => $type,
            'quantity_before' => bccomp($quantity, '0', 4) < 0 ? '10.0000' : '10.0000',
            'quantity_change' => $quantity,
            'quantity_after' => bcadd('10.0000', $quantity, 4),
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'posted_by' => $actorId,
            'posted_at' => '2026-09-01 10:00:00+00',
        ]);
    }

    private function assertState(
        string $propertyId,
        string $itemId,
        string $locationId,
        int $sequence,
        string $quantity,
        string $value,
    ): void {
        $state = CostAvcoState::where('property_id', $propertyId)
            ->where('item_id', $itemId)->where('location_id', $locationId)->firstOrFail();
        $this->assertSame($sequence, (int) $state->last_valuation_sequence);
        $this->assertSame(0, bccomp($quantity, (string) $state->on_hand_quantity, 4));
        $this->assertSame(0, bccomp($value, (string) $state->carrying_value, 4));
    }
}
