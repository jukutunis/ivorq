<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Operations\Inventory\Models\InventoryValuationSequence;

class InventoryPostingSequenceAllocationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private InventoryPostingControlCoordinator $coordinator;
    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location1;
    private InventoryLocation $location2;
    private FinancialPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordinator = app(InventoryPostingControlCoordinator::class);
        $this->property = Property::first();

        // Ensure property has valid currency code
        $this->property->currency = 'USD';
        $this->property->save();

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->user = User::first();
        $this->actingAs($this->user);

        // Open Business Date and Financial Period
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            [
                'status' => PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_at' => now(),
                'opened_by' => $this->user->id
            ]
        );

        $this->period = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            [
                'status' => FinancialPeriodStatusEnum::Open,
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
            'sku' => 'ITM-PROD-999',
            'name' => 'Sequence Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active' => true,
        ]);

        $this->location1 = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store 1',
            'type' => 'internal',
        ]);

        $this->location2 = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store 2',
            'type' => 'internal',
        ]);

        // Seed initial physical quantity
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location1->id,
            'physical_quantity' => 100.0000,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location2->id,
            'physical_quantity' => 100.0000,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);
    }

    private function createPostingIntent(string $idemKey, string $locationId): InventoryLedgerPostingIntent
    {
        return new InventoryLedgerPostingIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            locationId: $locationId,
            businessDate: now()->toDateString(),
            occurredAt: now(),
            sourceDocumentType: 'receipt',
            sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'receipt_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: 'receive',
            idempotencyKey: $idemKey,
            transactionType: TransactionTypeEnum::PurchaseReceipt,
            quantityChange: '10.0000',
            unitCost: '5.5000',
            totalCost: '55.0000'
        );
    }

    public function test_first_posting_captures_immutable_valuation_evidence(): void
    {
        $idemKey = 'idem-first-posting';
        $intent = $this->createPostingIntent($idemKey, $this->location1->id);

        // Perform post
        $transaction = $this->coordinator->post($intent, $this->user->id);

        $this->assertNotNull($transaction->id);

        // Verify transaction fields
        $this->assertEquals($this->property->id, $transaction->property_id);
        $this->assertEquals($this->location1->id, $transaction->location_id);
        $this->assertEquals($this->item->id, $transaction->item_id);
        $this->assertEquals('USD', $transaction->currency_code);
        $this->assertEquals($this->period->id, $transaction->financial_period_id);

        $expectedScope = "property:{$this->property->id}:location:{$this->location1->id}:item:{$this->item->id}";
        $this->assertEquals($expectedScope, $transaction->valuation_scope);
        $this->assertEquals(1, $transaction->valuation_sequence);

        // Verify counter persists
        $counter = InventoryValuationSequence::where('property_id', $this->property->id)
            ->where('location_id', $this->location1->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertNotNull($counter);
        $this->assertEquals(1, $counter->last_sequence);

        // Verify outbox message exists
        $this->assertDatabaseCount('outbox_messages', 1);
        $message = OutboxMessage::first();
        $this->assertEquals(['transactionId' => $transaction->id], $message->payload);
    }

    public function test_same_scope_allocates_monotonically(): void
    {
        $intent1 = $this->createPostingIntent('idem-seq-1', $this->location1->id);
        $intent2 = $this->createPostingIntent('idem-seq-2', $this->location1->id);

        $tx1 = $this->coordinator->post($intent1, $this->user->id);
        $tx2 = $this->coordinator->post($intent2, $this->user->id);

        $this->assertEquals(1, $tx1->valuation_sequence);
        $this->assertEquals(2, $tx2->valuation_sequence);

        $counter = InventoryValuationSequence::where('property_id', $this->property->id)
            ->where('location_id', $this->location1->id)
            ->where('item_id', $this->item->id)
            ->first();

        $this->assertNotNull($counter);
        $this->assertEquals(2, $counter->last_sequence);
    }

    public function test_different_scopes_start_independently(): void
    {
        $intent1 = $this->createPostingIntent('idem-scope-1', $this->location1->id);
        $intent2 = $this->createPostingIntent('idem-scope-2', $this->location2->id);

        $tx1 = $this->coordinator->post($intent1, $this->user->id);
        $tx2 = $this->coordinator->post($intent2, $this->user->id);

        $this->assertEquals(1, $tx1->valuation_sequence);
        $this->assertEquals(1, $tx2->valuation_sequence);

        $counter1 = InventoryValuationSequence::where('property_id', $this->property->id)
            ->where('location_id', $this->location1->id)
            ->where('item_id', $this->item->id)
            ->first();

        $counter2 = InventoryValuationSequence::where('property_id', $this->property->id)
            ->where('location_id', $this->location2->id)
            ->where('item_id', $this->item->id)
            ->first();

        $this->assertEquals(1, $counter1->last_sequence);
        $this->assertEquals(1, $counter2->last_sequence);
    }

    public function test_valid_idempotent_replay_does_not_advance_sequence(): void
    {
        $intent = $this->createPostingIntent('idem-replay-seq', $this->location1->id);

        $tx1 = $this->coordinator->post($intent, $this->user->id);
        $tx2 = $this->coordinator->post($intent, $this->user->id);

        $this->assertEquals($tx1->id, $tx2->id);
        $this->assertEquals(1, $tx1->valuation_sequence);
        $this->assertEquals(1, $tx2->valuation_sequence);

        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('outbox_messages', 1);

        $counter = InventoryValuationSequence::where('property_id', $this->property->id)
            ->where('location_id', $this->location1->id)
            ->where('item_id', $this->item->id)
            ->first();

        $this->assertEquals(1, $counter->last_sequence);
    }

    public function test_invalid_property_currency_fails_atomically(): void
    {
        // Set invalid currency
        $this->property->currency = 'US';
        $this->property->save();

        $intent = $this->createPostingIntent('idem-invalid-currency', $this->location1->id);

        $thrown = false;
        try {
            $this->coordinator->post($intent, $this->user->id);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('Property currency is invalid or missing.', $e->getMessage());
        }

        $this->assertTrue($thrown, "Expected RuntimeException was not thrown.");

        // Verify no transaction, outbox, or sequence counter was saved
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('outbox_messages', 0);

        $counter = InventoryValuationSequence::where('property_id', $this->property->id)
            ->where('location_id', $this->location1->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertNull($counter);
    }
}
