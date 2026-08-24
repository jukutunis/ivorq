<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Finance\CostControl\Adapters\InventoryCostDeliveryModeAdapter;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresTestCase;

class InventoryCostDeliveryModeStampTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_migration_does_not_backfill_historical_inventory_transactions_and_reapplies(): void
    {
        $migration = require base_path('Modules/Operations/Inventory/database/migrations/2026_08_21_000400_add_cost_delivery_mode_evidence_to_inventory_transactions_table.php');
        $migration->down();

        $historicalId = $this->insertLegacyTransaction();
        $this->assertFalse(Schema::hasColumn('inventory_transactions', 'cost_delivery_mode'));

        $migration->up();

        $historical = DB::table('inventory_transactions')->where('id', $historicalId)->first();
        $this->assertNull($historical->cost_delivery_mode);
        $this->assertNull($historical->cost_delivery_ownership_id);
        $this->assertNull($historical->cost_delivery_ownership_version);
        $this->assertNull($historical->cost_delivery_cutover_id);
    }

    public function test_valid_synchronous_and_deferred_structural_stamps_are_accepted(): void
    {
        $syncId = $this->insertLegacyTransaction([
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_ownership_id' => (string) Str::ulid(),
            'cost_delivery_ownership_version' => 1,
            'cost_delivery_cutover_id' => null,
        ]);
        $deferredId = $this->insertLegacyTransaction([
            'cost_delivery_mode' => 'DEFERRED',
            'cost_delivery_ownership_id' => (string) Str::ulid(),
            'cost_delivery_ownership_version' => 2,
            'cost_delivery_cutover_id' => (string) Str::ulid(),
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $syncId,
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_ownership_version' => 1,
            'cost_delivery_cutover_id' => null,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $deferredId,
            'cost_delivery_mode' => 'DEFERRED',
            'cost_delivery_ownership_version' => 2,
        ]);
    }

    #[DataProvider('invalidStampProvider')]
    public function test_partial_or_invalid_source_stamp_is_rejected(array $stamp): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/chk_inv_tx_cost_delivery_stamp/');
        $this->insertLegacyTransaction($stamp);
    }

    public static function invalidStampProvider(): array
    {
        return [
            'mode only' => [[
                'cost_delivery_mode' => 'SYNCHRONOUS',
            ]],
            'ownership without mode' => [[
                'cost_delivery_ownership_id' => '01J00000000000000000000000',
                'cost_delivery_ownership_version' => 1,
            ]],
            'synchronous with cutover' => [[
                'cost_delivery_mode' => 'SYNCHRONOUS',
                'cost_delivery_ownership_id' => '01J00000000000000000000000',
                'cost_delivery_ownership_version' => 1,
                'cost_delivery_cutover_id' => '01J00000000000000000000001',
            ]],
            'deferred without cutover' => [[
                'cost_delivery_mode' => 'DEFERRED',
                'cost_delivery_ownership_id' => '01J00000000000000000000000',
                'cost_delivery_ownership_version' => 2,
            ]],
            'unsupported mode' => [[
                'cost_delivery_mode' => 'HYBRID',
                'cost_delivery_ownership_id' => '01J00000000000000000000000',
                'cost_delivery_ownership_version' => 1,
            ]],
        ];
    }

    public function test_inventory_repository_stamps_only_server_resolved_decision_facts(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $ownershipId = (string) Str::ulid();
        $valuationScope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
        $decision = CostDeliveryPostingDecision::synchronous(
            $propertyId,
            $itemId,
            $locationId,
            $valuationScope,
            $ownershipId,
            1,
        );
        $intent = new InventoryLedgerPostingIntent(
            propertyId: $propertyId,
            itemId: $itemId,
            locationId: $locationId,
            businessDate: '2026-08-21',
            occurredAt: now(),
            sourceDocumentType: 'inventory_adjustment',
            sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'inventory_adjustment_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: 'adjustment_in',
            idempotencyKey: 'cc-p01a-stamp-'.Str::random(12),
            transactionType: TransactionTypeEnum::AdjustmentIn,
            quantityChange: '1.0000',
            unitCost: '2.0000',
            totalCost: '2.0000',
        );

        $transaction = app(InventoryTransactionRepository::class)->appendControlled(
            intent: $intent,
            quantityBefore: '0.0000',
            quantityAfter: '1.0000',
            valuationApprovalStatus: 'approved',
            valuationApprovalReference: 'inventory_adjustment:test:approved',
            actorId: (string) Str::ulid(),
            currencyCode: 'USD',
            financialPeriodId: (string) Str::ulid(),
            valuationScope: $valuationScope,
            valuationSequence: 1,
            costDeliveryDecision: $decision,
        );

        $this->assertSame('SYNCHRONOUS', $transaction->cost_delivery_mode);
        $this->assertSame($ownershipId, $transaction->cost_delivery_ownership_id);
        $this->assertSame(1, $transaction->cost_delivery_ownership_version);
        $this->assertNull($transaction->cost_delivery_cutover_id);
    }

    public function test_repository_rejects_owned_decision_for_another_location_before_insert(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $decisionLocationId = (string) Str::ulid();
        $intentLocationId = (string) Str::ulid();
        $sourceDocumentId = (string) Str::ulid();
        $decisionScope = $this->canonicalScope($propertyId, $decisionLocationId, $itemId);
        $intentScope = $this->canonicalScope($propertyId, $intentLocationId, $itemId);
        $decision = CostDeliveryPostingDecision::synchronous(
            $propertyId,
            $itemId,
            $decisionLocationId,
            $decisionScope,
            (string) Str::ulid(),
            1,
        );

        try {
            $this->appendDecision(
                $this->makePostingIntent($propertyId, $itemId, $intentLocationId, $sourceDocumentId),
                $intentScope,
                $decision,
            );
            $this->fail('A decision for another Inventory Location must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Cost delivery posting decision scope does not match the Inventory intent.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('inventory_transactions', ['source_document_id' => $sourceDocumentId]);
    }

    public function test_repository_rejects_owned_decision_with_different_transaction_scope_before_insert(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $sourceDocumentId = (string) Str::ulid();
        $decisionScope = $this->canonicalScope($propertyId, $locationId, $itemId);
        $transactionScope = $this->canonicalScope($propertyId, (string) Str::ulid(), $itemId);
        $decision = CostDeliveryPostingDecision::synchronous(
            $propertyId,
            $itemId,
            $locationId,
            $decisionScope,
            (string) Str::ulid(),
            1,
        );

        try {
            $this->appendDecision(
                $this->makePostingIntent($propertyId, $itemId, $locationId, $sourceDocumentId),
                $transactionScope,
                $decision,
            );
            $this->fail('A decision with a different Inventory valuation scope must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Cost delivery posting decision scope does not match the Inventory intent.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('inventory_transactions', ['source_document_id' => $sourceDocumentId]);
    }

    public function test_repository_rejects_owned_decision_when_transaction_scope_is_null_before_insert(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $sourceDocumentId = (string) Str::ulid();
        $decision = CostDeliveryPostingDecision::synchronous(
            $propertyId,
            $itemId,
            $locationId,
            $this->canonicalScope($propertyId, $locationId, $itemId),
            (string) Str::ulid(),
            1,
        );

        try {
            $this->appendDecision(
                $this->makePostingIntent($propertyId, $itemId, $locationId, $sourceDocumentId),
                null,
                $decision,
            );
            $this->fail('An owned decision requires a non-null Inventory valuation scope.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Cost delivery posting decision scope does not match the Inventory intent.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('inventory_transactions', ['source_document_id' => $sourceDocumentId]);
    }

    public function test_synchronous_decision_rejects_malformed_canonical_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exact canonical Property/Location/Item valuation scope');
        CostDeliveryPostingDecision::synchronous(
            'P1',
            'I1',
            'L1',
            'arbitrary-non-empty-scope',
            (string) Str::ulid(),
            1,
        );
    }

    public function test_deferred_decision_rejects_scope_for_another_location(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exact canonical Property/Location/Item valuation scope');
        CostDeliveryPostingDecision::deferred(
            'P1',
            'I1',
            'L1',
            'property:P1:location:L2:item:I1',
            (string) Str::ulid(),
            2,
            (string) Str::ulid(),
            5,
            6,
        );
    }

    public function test_deferred_decision_accepts_exact_canonical_scope_and_watermark(): void
    {
        $decision = CostDeliveryPostingDecision::deferred(
            'P1',
            'I1',
            'L1',
            'property:P1:location:L1:item:I1',
            'OWNERSHIP-1',
            2,
            'CUTOVER-1',
            5,
            6,
        );

        $this->assertSame('P1', $decision->propertyId);
        $this->assertSame('I1', $decision->itemId);
        $this->assertSame('L1', $decision->locationId);
        $this->assertSame('property:P1:location:L1:item:I1', $decision->valuationScope);
        $this->assertSame(CostDeliveryPostingDecision::DEFERRED, $decision->outcome);
        $this->assertSame(5, $decision->lastSynchronouslyOwnedSequence);
        $this->assertSame(6, $decision->firstDeferredOwnedSequence);
    }

    public function test_not_enrolled_decision_preserves_all_null_source_stamp_and_scope_provenance(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $decision = CostDeliveryPostingDecision::notEnrolled($propertyId, $itemId);

        $this->assertNull($decision->locationId);
        $this->assertNull($decision->valuationScope);
        $this->assertNull($decision->deliveryMode);
        $this->assertNull($decision->ownershipId);
        $this->assertNull($decision->ownershipVersion);
        $this->assertNull($decision->cutoverId);
        $this->assertNull($decision->lastSynchronouslyOwnedSequence);
        $this->assertNull($decision->firstDeferredOwnedSequence);

        $transaction = $this->appendDecision(
            $this->makePostingIntent($propertyId, $itemId, $locationId),
            $this->canonicalScope($propertyId, $locationId, $itemId),
            $decision,
        );
        $this->assertNull($transaction->cost_delivery_mode);
        $this->assertNull($transaction->cost_delivery_ownership_id);
        $this->assertNull($transaction->cost_delivery_ownership_version);
        $this->assertNull($transaction->cost_delivery_cutover_id);
    }

    public function test_source_stamp_fields_are_covered_by_inventory_transaction_immutability(): void
    {
        $id = $this->insertLegacyTransaction([
            'cost_delivery_mode' => 'SYNCHRONOUS',
            'cost_delivery_ownership_id' => (string) Str::ulid(),
            'cost_delivery_ownership_version' => 1,
            'cost_delivery_cutover_id' => null,
        ]);

        try {
            DB::table('inventory_transactions')->where('id', $id)->update([
                'cost_delivery_ownership_version' => 2,
            ]);
            $this->fail('Immutable source stamp UPDATE must be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Inventory transactions are immutable and cannot be updated or deleted.',
                $exception->getMessage(),
            );
        }

        $this->expectException(QueryException::class);
        DB::table('inventory_transactions')->where('id', $id)->delete();
    }

    public function test_repository_rejects_decision_identity_mismatched_to_inventory_intent(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $sourceDocumentId = (string) Str::ulid();
        $intent = new InventoryLedgerPostingIntent(
            propertyId: $propertyId,
            itemId: $itemId,
            locationId: $locationId,
            businessDate: '2026-08-21',
            occurredAt: now(),
            sourceDocumentType: 'inventory_adjustment',
            sourceDocumentId: $sourceDocumentId,
            sourceLineType: 'inventory_adjustment_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: 'adjustment_in',
            idempotencyKey: 'cc-p01a-mismatch-'.Str::random(12),
            transactionType: TransactionTypeEnum::AdjustmentIn,
            quantityChange: '1.0000',
            unitCost: '1.0000',
            totalCost: '1.0000',
        );
        $decisionPropertyId = (string) Str::ulid();
        $decisionLocationId = (string) Str::ulid();
        $decision = CostDeliveryPostingDecision::synchronous(
            $decisionPropertyId,
            $itemId,
            $decisionLocationId,
            $this->canonicalScope($decisionPropertyId, $decisionLocationId, $itemId),
            (string) Str::ulid(),
            1,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Property\/Item identity/');
        app(InventoryTransactionRepository::class)->appendControlled(
            intent: $intent,
            quantityBefore: '0.0000',
            quantityAfter: '1.0000',
            valuationApprovalStatus: 'approved',
            valuationApprovalReference: 'inventory_adjustment:test:approved',
            costDeliveryDecision: $decision,
        );
    }

    public function test_repository_rejects_owned_decision_item_mismatch_before_insert(): void
    {
        $propertyId = (string) Str::ulid();
        $intentItemId = (string) Str::ulid();
        $decisionItemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $sourceDocumentId = (string) Str::ulid();
        $decision = CostDeliveryPostingDecision::synchronous(
            $propertyId,
            $decisionItemId,
            $locationId,
            $this->canonicalScope($propertyId, $locationId, $decisionItemId),
            (string) Str::ulid(),
            1,
        );

        try {
            $this->appendDecision(
                $this->makePostingIntent($propertyId, $intentItemId, $locationId, $sourceDocumentId),
                $this->canonicalScope($propertyId, $locationId, $intentItemId),
                $decision,
            );
            $this->fail('A decision for another Inventory Item must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Cost delivery posting decision Property/Item identity does not match the Inventory intent.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('inventory_transactions', ['source_document_id' => $sourceDocumentId]);
    }

    public function test_inventory_owned_port_is_bound_to_costcontrol_adapter_without_reverse_implementation_import(): void
    {
        $this->assertInstanceOf(InventoryCostDeliveryModeAdapter::class, app(CostDeliveryModePort::class));

        $inventorySources = file_get_contents(base_path('Modules/Operations/Inventory/Contracts/CostDeliveryModePort.php'))
            .file_get_contents(base_path('Modules/Operations/Inventory/ValueObjects/CostDeliveryPostingDecision.php'))
            .file_get_contents(base_path('Modules/Operations/Inventory/Repositories/InventoryTransactionRepository.php'));
        $this->assertStringNotContainsString('Modules\\Finance\\CostControl', $inventorySources);
    }

    private function insertLegacyTransaction(array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('inventory_transactions')->insert(array_merge([
            'id' => $id,
            'property_id' => (string) Str::ulid(),
            'item_id' => (string) Str::ulid(),
            'location_id' => (string) Str::ulid(),
            'transaction_type' => 'receipt',
            'quantity_before' => 0,
            'quantity_change' => 1,
            'quantity_after' => 1,
            'unit_cost' => 0,
            'total_cost' => 0,
            'posted_at' => now(),
        ], $overrides));

        return $id;
    }

    private function canonicalScope(string $propertyId, string $locationId, string $itemId): string
    {
        return "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
    }

    private function makePostingIntent(
        string $propertyId,
        string $itemId,
        string $locationId,
        ?string $sourceDocumentId = null
    ): InventoryLedgerPostingIntent {
        return new InventoryLedgerPostingIntent(
            propertyId: $propertyId,
            itemId: $itemId,
            locationId: $locationId,
            businessDate: '2026-08-21',
            occurredAt: now(),
            sourceDocumentType: 'inventory_adjustment',
            sourceDocumentId: $sourceDocumentId ?? (string) Str::ulid(),
            sourceLineType: 'inventory_adjustment_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: 'adjustment_in',
            idempotencyKey: 'cc-p01a-scope-'.Str::random(12),
            transactionType: TransactionTypeEnum::AdjustmentIn,
            quantityChange: '1.0000',
            unitCost: '2.0000',
            totalCost: '2.0000',
        );
    }

    private function appendDecision(
        InventoryLedgerPostingIntent $intent,
        ?string $valuationScope,
        CostDeliveryPostingDecision $decision
    ): InventoryTransaction {
        return app(InventoryTransactionRepository::class)->appendControlled(
            intent: $intent,
            quantityBefore: '0.0000',
            quantityAfter: '1.0000',
            valuationApprovalStatus: 'approved',
            valuationApprovalReference: 'inventory_adjustment:test:approved',
            actorId: (string) Str::ulid(),
            currencyCode: 'USD',
            financialPeriodId: (string) Str::ulid(),
            valuationScope: $valuationScope,
            valuationSequence: 1,
            costDeliveryDecision: $decision,
        );
    }
}
