<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;

class InventoryPostingValuationAuthorizationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private InventoryPostingControlCoordinator $coordinator;
    private InventoryTransactionRepository $transactionRepo;
    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location1;
    private InventoryLocation $location2;
    private FinancialPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordinator   = app(InventoryPostingControlCoordinator::class);
        $this->transactionRepo = app(InventoryTransactionRepository::class);
        $this->property      = Property::first();

        $this->property->currency = 'USD';
        $this->property->save();

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->user = User::first();
        $this->actingAs($this->user);

        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            [
                'status'    => PropertyBusinessDateStatusEnum::Open,
                'is_open'   => true,
                'opened_at' => now(),
                'opened_by' => $this->user->id,
            ]
        );

        $this->period = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            [
                'status'     => FinancialPeriodStatusEnum::Open,
                'start_date' => now()->startOfMonth(),
                'end_date'   => now()->endOfMonth(),
            ]
        );

        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'General',
        ]);

        $this->item = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $invCategory->id,
            'sku'                   => 'ITM-AUTH-001',
            'name'                  => 'Auth Evidence Test Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location1 = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Auth Store 1',
            'type'        => 'internal',
        ]);

        $this->location2 = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Auth Store 2',
            'type'        => 'internal',
        ]);

        InventoryStock::create([
            'property_id'       => $this->property->id,
            'item_id'           => $this->item->id,
            'location_id'       => $this->location1->id,
            'physical_quantity' => '100.0000',
            'status'            => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        InventoryStock::create([
            'property_id'       => $this->property->id,
            'item_id'           => $this->item->id,
            'location_id'       => $this->location2->id,
            'physical_quantity' => '100.0000',
            'status'            => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);
    }

    public function test_issue_posting_records_posted_authorization_evidence(): void
    {
        $sourceDocumentId = (string) Str::ulid();

        $intent = new InventoryLedgerPostingIntent(
            propertyId:         $this->property->id,
            itemId:             $this->item->id,
            locationId:         $this->location1->id,
            businessDate:       now()->toDateString(),
            occurredAt:         now(),
            sourceDocumentType: 'inventory_issue',
            sourceDocumentId:   $sourceDocumentId,
            sourceLineType:     'inventory_issue_line',
            sourceLineId:       (string) Str::ulid(),
            movementRole:       'issue',
            idempotencyKey:     'auth-issue-' . Str::ulid(),
            transactionType:    TransactionTypeEnum::Issue,
            quantityChange:     '-5.0000',
            unitCost:           '10.0000',
            totalCost:          '-50.0000'
        );

        $tx = $this->coordinator->post($intent, $this->user->id);
        $fresh = $tx->fresh();

        $this->assertEquals('approved', $fresh->valuation_approval_status);
        $this->assertEquals("inventory_issue:{$sourceDocumentId}:posted", $fresh->valuation_approval_reference);
    }

    public function test_adjustment_posting_records_approved_authorization_evidence(): void
    {
        $sourceDocumentId = (string) Str::ulid();

        $intent = new InventoryLedgerPostingIntent(
            propertyId:         $this->property->id,
            itemId:             $this->item->id,
            locationId:         $this->location1->id,
            businessDate:       now()->toDateString(),
            occurredAt:         now(),
            sourceDocumentType: 'inventory_adjustment',
            sourceDocumentId:   $sourceDocumentId,
            sourceLineType:     'inventory_adjustment_line',
            sourceLineId:       (string) Str::ulid(),
            movementRole:       'adjustment_in',
            idempotencyKey:     'auth-adj-' . Str::ulid(),
            transactionType:    TransactionTypeEnum::AdjustmentIn,
            quantityChange:     '5.0000',
            unitCost:           '10.0000',
            totalCost:          '50.0000'
        );

        $tx = $this->coordinator->post($intent, $this->user->id);
        $fresh = $tx->fresh();

        $this->assertEquals('approved', $fresh->valuation_approval_status);
        $this->assertEquals("inventory_adjustment:{$sourceDocumentId}:approved", $fresh->valuation_approval_reference);
    }

    public function test_transfer_pair_records_completed_authorization_evidence_on_distinct_scopes(): void
    {
        $transferDocumentId = (string) Str::ulid();
        $lineId             = (string) Str::ulid();

        $intentOut = new InventoryLedgerPostingIntent(
            propertyId:         $this->property->id,
            itemId:             $this->item->id,
            locationId:         $this->location1->id,
            businessDate:       now()->toDateString(),
            occurredAt:         now(),
            sourceDocumentType: 'inventory_transfer',
            sourceDocumentId:   $transferDocumentId,
            sourceLineType:     'inventory_transfer_line',
            sourceLineId:       $lineId,
            movementRole:       'transfer_out',
            idempotencyKey:     "trf-{$transferDocumentId}-{$lineId}-out",
            transactionType:    TransactionTypeEnum::TransferOut,
            quantityChange:     '-5.0000',
            unitCost:           '10.0000',
            totalCost:          '-50.0000'
        );

        $intentIn = new InventoryLedgerPostingIntent(
            propertyId:         $this->property->id,
            itemId:             $this->item->id,
            locationId:         $this->location2->id,
            businessDate:       now()->toDateString(),
            occurredAt:         now(),
            sourceDocumentType: 'inventory_transfer',
            sourceDocumentId:   $transferDocumentId,
            sourceLineType:     'inventory_transfer_line',
            sourceLineId:       $lineId,
            movementRole:       'transfer_in',
            idempotencyKey:     "trf-{$transferDocumentId}-{$lineId}-in",
            transactionType:    TransactionTypeEnum::TransferIn,
            quantityChange:     '5.0000',
            unitCost:           '10.0000',
            totalCost:          '50.0000'
        );

        $txOut = $this->coordinator->post($intentOut, $this->user->id);
        $txIn  = $this->coordinator->post($intentIn, $this->user->id);

        $freshOut = $txOut->fresh();
        $freshIn  = $txIn->fresh();

        $expectedRef = "inventory_transfer:{$transferDocumentId}:completed";

        $this->assertEquals('approved', $freshOut->valuation_approval_status);
        $this->assertEquals($expectedRef, $freshOut->valuation_approval_reference);

        $this->assertEquals('approved', $freshIn->valuation_approval_status);
        $this->assertEquals($expectedRef, $freshIn->valuation_approval_reference);

        $this->assertNotEquals($freshOut->valuation_scope, $freshIn->valuation_scope);
        $this->assertStringContainsString(":location:{$this->location1->id}:", $freshOut->valuation_scope);
        $this->assertStringContainsString(":location:{$this->location2->id}:", $freshIn->valuation_scope);
    }

    public function test_unsupported_transaction_type_fails_closed_before_any_db_write(): void
    {
        $thrown = false;
        try {
            $intent = new InventoryLedgerPostingIntent(
                propertyId:         $this->property->id,
                itemId:             $this->item->id,
                locationId:         $this->location1->id,
                businessDate:       now()->toDateString(),
                occurredAt:         now(),
                sourceDocumentType: 'receipt',
                sourceDocumentId:   (string) Str::ulid(),
                sourceLineType:     'receipt_line',
                sourceLineId:       (string) Str::ulid(),
                movementRole:       'receive',
                idempotencyKey:     'auth-unsupported-' . Str::ulid(),
                transactionType:    TransactionTypeEnum::PurchaseReceipt,
                quantityChange:     '10.0000',
                unitCost:           '5.0000',
                totalCost:          '50.0000'
            );
            $this->coordinator->post($intent, $this->user->id);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString("unsupported transaction type", $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException for unsupported transaction type.');
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_legacy_row_without_idempotency_key_is_not_backfilled_with_authorization_evidence(): void
    {
        $legacyTx = $this->transactionRepo->create([
            'property_id'      => $this->property->id,
            'item_id'          => $this->item->id,
            'location_id'      => $this->location1->id,
            'transaction_type' => TransactionTypeEnum::Issue->value,
            'quantity_before'  => '100.0000',
            'quantity_change'  => '-5.0000',
            'quantity_after'   => '95.0000',
            'unit_cost'        => '10.00',
            'total_cost'       => '-50.00',
            'posted_at'        => now(),
            // no idempotency_key — legacy row; constraint does not apply
            // no valuation_approval_status
            // no valuation_approval_reference
        ]);

        $fresh = $legacyTx->fresh();

        $this->assertNull($fresh->valuation_approval_status,
            'Migration must not backfill valuation_approval_status on legacy rows.');
        $this->assertNull($fresh->valuation_approval_reference,
            'Migration must not backfill valuation_approval_reference on legacy rows.');
    }
}
