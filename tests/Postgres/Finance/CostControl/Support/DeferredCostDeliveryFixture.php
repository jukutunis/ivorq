<?php

namespace Tests\Postgres\Finance\CostControl\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryOutboxDispositionRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryDispositionDecision;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;

trait DeferredCostDeliveryFixture
{
    protected Property $property;

    protected User $actor;

    protected InventoryItem $item;

    protected InventoryLocation $location;

    protected InventoryLocation $partnerLocation;

    protected FinancialPeriod $period;

    protected PropertyBusinessDate $businessDate;

    protected CostAuthorityEnrollmentGroup $group;

    /** @var array{ownership_id:string,ownership_version:int,cutover_id:string} */
    protected array $deliveryEvidence;

    protected function setUpDeferredFixture(
        string $sourceOpeningQuantity = '0.0000',
        string $sourceOpeningValue = '0.0000',
        string $destinationOpeningQuantity = '0.0000',
        string $destinationOpeningValue = '0.0000',
    ): void {
        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'CC-P01E Deferred Consumer',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'CCP01E-'.Str::upper(Str::random(10)),
            'name' => 'CC-P01E Deferred Consumer Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 999,
            'is_active' => true,
        ]);
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01E Source '.Str::random(8),
            'type' => 'internal',
        ]);
        $this->partnerLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01E Destination '.Str::random(8),
            'type' => 'internal',
        ]);
        $this->period = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Open],
        );
        $this->businessDate = PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => '2026-08-25'],
            [
                'timezone_snapshot' => $this->property->timezone,
                'status' => PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_by' => $this->actor->id,
                'opened_at' => now(),
            ],
        );

        [$this->group, $this->deliveryEvidence] = $this->createDeferredEvidence(
            $sourceOpeningQuantity,
            $sourceOpeningValue,
            $destinationOpeningQuantity,
            $destinationOpeningValue,
        );
    }

    protected function makeDeferredSource(
        TransactionTypeEnum $type = TransactionTypeEnum::PurchaseReceipt,
        array $overrides = [],
    ): InventoryTransaction {
        $id = (string) Str::ulid();
        $quantity = match ($type) {
            TransactionTypeEnum::Issue,
            TransactionTypeEnum::AdjustmentOut,
            TransactionTypeEnum::TransferOut => '-2.0000',
            default => '2.0000',
        };
        $total = str_starts_with($quantity, '-') ? '-15.0000' : '15.0000';
        $attributes = array_merge([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'currency_code' => 'USD',
            'financial_period_id' => $this->period->id,
            'valuation_scope' => $this->scope($this->location),
            'valuation_sequence' => 1,
            'valuation_approval_status' => 'approved',
            'valuation_approval_reference' => 'CC-P01E-APPROVED',
            'cost_delivery_mode' => 'DEFERRED',
            'cost_delivery_ownership_id' => $this->deliveryEvidence['ownership_id'],
            'cost_delivery_ownership_version' => $this->deliveryEvidence['ownership_version'],
            'cost_delivery_cutover_id' => $this->deliveryEvidence['cutover_id'],
            'business_date' => '2026-08-25',
            'occurred_at' => '2026-08-25 10:00:00',
            'source_document_type' => 'cc_p01e_document',
            'source_document_id' => (string) Str::ulid(),
            'source_line_type' => 'cc_p01e_line',
            'source_line_id' => (string) Str::ulid(),
            'movement_role' => $type->value,
            'idempotency_key' => 'ccp01e-'.$id,
            'transaction_type' => $type,
            'quantity_before' => str_starts_with($quantity, '-') ? '10.0000' : '0.0000',
            'quantity_change' => $quantity,
            'quantity_after' => str_starts_with($quantity, '-') ? '8.0000' : '2.0000',
            'unit_cost' => '7.5000',
            'total_cost' => $total,
            'posted_by' => $this->actor->id,
            'posted_at' => '2026-08-25 10:00:00',
            'created_at' => now(),
        ], $overrides);

        return InventoryTransaction::create($attributes)->fresh();
    }

    protected function makeOutbox(InventoryTransaction $source): OutboxMessage
    {
        return app(OutboxRepository::class)->createPending([
            'topic' => 'inventory.transaction.posted',
            'source_inventory_transaction_id' => $source->id,
            'payload' => ['transactionId' => $source->id],
            'idempotency_key' => "inventory_transaction:{$source->id}:cost_ledger",
        ]);
    }

    /** @return array{outbound:InventoryTransaction,inbound:InventoryTransaction,outbound_outbox:OutboxMessage,inbound_outbox:OutboxMessage} */
    protected function makeTransferPair(int $outboundSequence = 1, int $inboundSequence = 1): array
    {
        $documentId = (string) Str::ulid();
        $lineId = (string) Str::ulid();
        $common = [
            'source_document_type' => 'inventory_transfer',
            'source_document_id' => $documentId,
            'source_line_type' => 'inventory_transfer_line',
            'source_line_id' => $lineId,
            'occurred_at' => '2026-08-25 11:00:00',
            'posted_at' => '2026-08-25 11:00:00',
        ];
        $outbound = $this->makeDeferredSource(TransactionTypeEnum::TransferOut, array_merge($common, [
            'valuation_sequence' => $outboundSequence,
            'quantity_before' => '10.0000',
            'quantity_change' => '-2.0000',
            'quantity_after' => '8.0000',
            'unit_cost' => '7.5000',
            'total_cost' => '-15.0000',
        ]));
        $inbound = $this->makeDeferredSource(TransactionTypeEnum::TransferIn, array_merge($common, [
            'location_id' => $this->partnerLocation->id,
            'valuation_scope' => $this->scope($this->partnerLocation),
            'valuation_sequence' => $inboundSequence,
            'quantity_before' => '0.0000',
            'quantity_change' => '2.0000',
            'quantity_after' => '2.0000',
            'unit_cost' => '7.5000',
            'total_cost' => '15.0000',
        ]));

        return [
            'outbound' => $outbound,
            'inbound' => $inbound,
            'outbound_outbox' => $this->makeOutbox($outbound),
            'inbound_outbox' => $this->makeOutbox($inbound),
        ];
    }

    protected function classifyManually(InventoryTransaction $source, OutboxMessage $outbox): CostDeliveryOutboxDisposition
    {
        return DB::transaction(fn (): CostDeliveryOutboxDisposition => app(CostDeliveryOutboxDispositionRepository::class)
            ->persistDeferred(CostDeliveryDispositionDecision::deferredOwnedAfterCutover(
                outboxMessageId: $outbox->id,
                sourceInventoryTransactionId: $source->id,
                propertyId: $source->property_id,
                locationId: $source->location_id,
                itemId: $source->item_id,
                valuationScope: $source->valuation_scope,
                valuationSequence: $source->valuation_sequence,
                costDeliveryOwnershipId: $this->deliveryEvidence['ownership_id'],
                costDeliveryOwnershipVersion: $this->deliveryEvidence['ownership_version'],
                costDeliveryCutoverId: $this->deliveryEvidence['cutover_id'],
                classifiedBy: $this->actor->id,
                classifiedAt: now(),
            )));
    }

    protected function state(InventoryLocation $location): CostAvcoState
    {
        return CostAvcoState::where('property_id', $this->property->id)
            ->where('location_id', $location->id)
            ->where('item_id', $this->item->id)
            ->firstOrFail();
    }

    protected function scope(InventoryLocation $location): string
    {
        return "property:{$this->property->id}:location:{$location->id}:item:{$this->item->id}";
    }

    protected function rawUpdate(string $table, string $id, array $changes): void
    {
        $this->withoutTriggers([$table], fn () => DB::table($table)->where('id', $id)->update($changes));
    }

    /** @param list<string> $tables */
    protected function withoutTriggers(array $tables, Closure $callback): void
    {
        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER ALL");
        }
        try {
            $callback();
        } finally {
            foreach (array_reverse($tables) as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER ALL");
            }
        }
    }

    /** @return array{CostAuthorityEnrollmentGroup,array{ownership_id:string,ownership_version:int,cutover_id:string}} */
    private function createDeferredEvidence(
        string $sourceOpeningQuantity,
        string $sourceOpeningValue,
        string $destinationOpeningQuantity,
        string $destinationOpeningValue,
    ): array {
        $repository = app(CostAuthorityEnrollmentRepository::class);
        $group = $repository->createDraft(
            ['property_id' => $this->property->id, 'item_id' => $this->item->id],
            [
                $this->snapshot($this->location, $sourceOpeningQuantity, $sourceOpeningValue, 'SOURCE'),
                $this->snapshot($this->partnerLocation, $destinationOpeningQuantity, $destinationOpeningValue, 'DESTINATION'),
            ],
        );
        DB::transaction(fn () => $repository->approve($group->id, $this->actor->id, now()));
        app(CostAuthorityEnrollmentBaselineSeedService::class)->seedApprovedGroup($group->id, $this->actor->id);
        $ownership = app(CostAuthorityEnrollmentActivationService::class)->activate($group->id, $this->actor->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('cost_delivery_pilot_properties')->insert([
            'id' => (string) Str::ulid(),
            'pilot_slot' => 1,
            'property_id' => $this->property->id,
            'owner_approval_reference' => 'CC-P01E-TEST-ONLY',
            'authorized_by' => $this->actor->id,
            'authorized_at' => now(),
            'created_at' => now(),
        ]);
        $snapshots = DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $group->id)
            ->orderBy('valuation_scope')
            ->get();
        $cutoverId = (string) Str::ulid();

        DB::transaction(function () use ($ownership, $group, $snapshots, $cutoverId): void {
            DB::table('cost_delivery_cutovers')->insert([
                'id' => $cutoverId,
                'ownership_id' => $ownership->id,
                'enrollment_group_id' => $group->id,
                'property_id' => $this->property->id,
                'item_id' => $this->item->id,
                'financial_period_id' => $this->period->id,
                'boundary_business_date' => '2026-08-25',
                'owner_approval_reference' => 'CC-P01E-TEST-ONLY',
                'requested_by' => $this->actor->id,
                'requested_at' => now()->subMinutes(2),
                'approved_by' => $this->actor->id,
                'approved_at' => now()->subMinute(),
                'activated_by' => $this->actor->id,
                'activated_at' => now(),
                'created_at' => now(),
            ]);
            foreach ($snapshots as $snapshot) {
                DB::table('cost_delivery_cutover_scopes')->insert([
                    'id' => (string) Str::ulid(),
                    'cutover_id' => $cutoverId,
                    'enrollment_scope_snapshot_id' => $snapshot->id,
                    'property_id' => $this->property->id,
                    'location_id' => $snapshot->location_id,
                    'item_id' => $this->item->id,
                    'valuation_scope' => $snapshot->valuation_scope,
                    'inventory_sequence_source' => 'ALLOCATOR_ABSENT',
                    'inventory_valuation_sequence_id' => null,
                    'inventory_allocator_last_sequence' => 0,
                    'cost_avco_last_valuation_sequence' => null,
                    'sequence_state_classification' => 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE',
                    'last_synchronously_owned_sequence' => 0,
                    'first_deferred_owned_sequence' => 1,
                    'created_at' => now(),
                ]);
            }
            DB::table('cost_delivery_mode_ownerships')->where('id', $ownership->id)->update([
                'delivery_mode' => 'DEFERRED',
                'ownership_version' => 2,
                'activated_cutover_id' => $cutoverId,
                'changed_by' => $this->actor->id,
                'changed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });

        return [$group->fresh(), [
            'ownership_id' => $ownership->id,
            'ownership_version' => 2,
            'cutover_id' => $cutoverId,
        ]];
    }

    /** @return array<string, mixed> */
    private function snapshot(
        InventoryLocation $location,
        string $quantity,
        string $value,
        string $suffix,
    ): array {
        return [
            'location_id' => $location->id,
            'valuation_scope' => $this->scope($location),
            'opening_quantity' => $quantity,
            'opening_carrying_value' => $value,
            'currency_code' => 'USD',
            'business_date' => '2026-08-25',
            'financial_period_id' => $this->period->id,
            'source_reference' => 'CC-P01E-'.$suffix,
            'evidence_timestamp' => now(),
        ];
    }
}
