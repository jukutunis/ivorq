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
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;

class InventoryPostingOutboxProducerTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private InventoryPostingControlCoordinator $coordinator;
    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordinator = app(InventoryPostingControlCoordinator::class);
        $this->property = Property::first();
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

        FinancialPeriod::updateOrCreate(
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
            'sku' => 'ITM-PROD-001',
            'name' => 'Test Producer Item',
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
            'physical_quantity' => 100.0000,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);
    }

    private function createPostingIntent(string $idemKey): InventoryLedgerPostingIntent
    {
        return new InventoryLedgerPostingIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            locationId: $this->location->id,
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

    public function test_new_controlled_posting_creates_one_durable_outbox_message(): void
    {
        $idemKey = 'idem-new-posting';
        $intent = $this->createPostingIntent($idemKey);

        $stockBefore = InventoryStock::where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();
        $qtyBefore = (float)$stockBefore->physical_quantity;

        // Perform post
        $transaction = $this->coordinator->post($intent, $this->user->id);

        $this->assertNotNull($transaction->id);

        // Verify stock mutation
        $stockAfter = $stockBefore->fresh();
        $this->assertEquals($qtyBefore + 10.00, (float)$stockAfter->physical_quantity);

        // Verify outbox message
        $this->assertDatabaseCount('outbox_messages', 1);
        $message = OutboxMessage::first();

        $this->assertNotNull($message->id);
        $this->assertEquals('inventory.transaction.posted', $message->topic);
        $this->assertEquals($transaction->id, $message->source_inventory_transaction_id);
        $this->assertEquals(['transactionId' => $transaction->id], $message->payload);
        $this->assertEquals("inventory_transaction:{$transaction->id}:cost_ledger", $message->idempotency_key);
        $this->assertEquals(OutboxStatusEnum::Pending, $message->status);
        $this->assertEquals(0, $message->attempts);
        $this->assertNull($message->last_error);
        $this->assertNull($message->delivered_at);
    }

    public function test_inventory_idempotent_replay_does_not_duplicate_outbox_or_stock(): void
    {
        $idemKey = 'idem-replay';
        $intent = $this->createPostingIntent($idemKey);

        // Perform first post
        $transaction1 = $this->coordinator->post($intent, $this->user->id);

        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('outbox_messages', 1);

        $stockAfterFirst = InventoryStock::where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();
        $qtyAfterFirst = (float)$stockAfterFirst->physical_quantity;

        // Perform replay
        $transaction2 = $this->coordinator->post($intent, $this->user->id);

        // Prove same transaction is returned
        $this->assertEquals($transaction1->id, $transaction2->id);

        // Prove counts remain exactly 1
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('outbox_messages', 1);

        // Prove stock does not mutate a second time
        $stockAfterReplay = $stockAfterFirst->fresh();
        $this->assertEquals($qtyAfterFirst, (float)$stockAfterReplay->physical_quantity);
    }

    public function test_outbox_persistence_failure_rolls_back_new_inventory_posting_atomically(): void
    {
        // Mock OutboxRepository to throw exception
        $mockOutboxRepository = $this->getMockBuilder(OutboxRepository::class)
            ->onlyMethods(['createPending'])
            ->getMock();

        $mockOutboxRepository->method('createPending')
            ->willThrowException(new \RuntimeException('Outbox creation failed simulated'));

        $this->app->instance(OutboxRepository::class, $mockOutboxRepository);

        // Resolve coordinator again to pick up the mock
        $coordinator = $this->app->make(InventoryPostingControlCoordinator::class);

        $intent = $this->createPostingIntent('idem-fail-rollback');

        $stockBefore = InventoryStock::where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();
        $qtyBefore = (float)$stockBefore->physical_quantity;

        // Expect Exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outbox creation failed simulated');

        try {
            $coordinator->post($intent, $this->user->id);
        } finally {
            // Verify rollback on both tables
            $this->assertDatabaseCount('inventory_transactions', 0);
            $this->assertDatabaseCount('outbox_messages', 0);

            // Verify stock remains exactly same
            $stockAfter = $stockBefore->fresh();
            $this->assertEquals($qtyBefore, (float)$stockAfter->physical_quantity);
        }
    }

    public function test_inventory_idempotency_collision_remains_fail_closed(): void
    {
        $idemKey = 'idem-collision';
        $intent1 = $this->createPostingIntent($idemKey);

        // Post first time
        $this->coordinator->post($intent1, $this->user->id);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('outbox_messages', 1);

        $stockAfterFirst = InventoryStock::where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();
        $qtyAfterFirst = (float)$stockAfterFirst->physical_quantity;

        // Mismatched posting intent using same idempotency key
        $intent2 = new InventoryLedgerPostingIntent(
            propertyId: $this->property->id,
            itemId: $this->item->id,
            locationId: $this->location->id,
            businessDate: now()->toDateString(),
            occurredAt: now(),
            sourceDocumentType: 'receipt',
            sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'receipt_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: 'receive',
            idempotencyKey: $idemKey, // same key
            transactionType: TransactionTypeEnum::PurchaseReceipt,
            quantityChange: '20.0000', // mismatched change
            unitCost: '5.5000',
            totalCost: '110.0000'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Idempotency collision: same key with different intent.');

        try {
            $this->coordinator->post($intent2, $this->user->id);
        } finally {
            // No new transaction, no new outbox message
            $this->assertDatabaseCount('inventory_transactions', 1);
            $this->assertDatabaseCount('outbox_messages', 1);

            // Stock does not mutate
            $stockAfterSecond = $stockAfterFirst->fresh();
            $this->assertEquals($qtyAfterFirst, (float)$stockAfterSecond->physical_quantity);
        }
    }
}
