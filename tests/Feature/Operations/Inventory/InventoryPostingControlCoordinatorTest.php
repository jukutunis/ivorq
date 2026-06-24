<?php

namespace Tests\Feature\Operations\Inventory;

use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Foundation\Property\Models\Property;
use RuntimeException;
use Tests\PostgresTestCase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InventoryPostingControlCoordinatorTest extends PostgresTestCase
{
    use RefreshDatabase, CreatesFoundationData;

    private InventoryPostingControlCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coordinator = app(InventoryPostingControlCoordinator::class);
    }

    private function createFixture(): array
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);

        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $property->id, 'name' => 'General']);

        $item = \Modules\Operations\Inventory\Models\InventoryItem::create([
            'property_id' => $property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-TEST',
            'name' => 'Test Item',
            'inventory_type' => 'goods',
            'is_active' => true,
        ]);

        $location = \Modules\Operations\Inventory\Models\InventoryLocation::create([
            'property_id' => $property->id,
            'name' => 'Main Store',
            'type' => 'internal',
        ]);

        $businessDate = PropertyBusinessDate::create([
            'property_id' => $property->id,
            'business_date' => now()->toDateString(),
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
        ]);

        $period = FinancialPeriod::create([
            'property_id' => $property->id,
            'period_year' => now()->year,
            'period_month' => now()->month,
            'status' => FinancialPeriodStatusEnum::Open,
        ]);

        return [$property, $item, $location, $businessDate, $period];
    }

    private function createIntent(array $fixture, ?string $idempotencyKey = null, string $qty = '10.0000'): InventoryLedgerPostingIntent
    {
        [$property, $item, $location] = $fixture;
        return new InventoryLedgerPostingIntent(
            propertyId: $property->id,
            itemId: $item->id,
            locationId: $location->id,
            businessDate: now()->toDateString(),
            occurredAt: Carbon::now(),
            sourceDocumentType: 'receipt',
            sourceDocumentId: (string) Str::ulid(),
            sourceLineType: 'receipt_line',
            sourceLineId: (string) Str::ulid(),
            movementRole: 'increase',
            idempotencyKey: $idempotencyKey ?? (string) Str::ulid(),
            transactionType: TransactionTypeEnum::PurchaseReceipt,
            quantityChange: $qty
        );
    }

    public function test_it_posts_successfully()
    {
        $fixture = $this->createFixture();
        $intent = $this->createIntent($fixture);

        $tx = $this->coordinator->post($intent);

        $this->assertNotNull($tx->id);
        $this->assertEquals($intent->quantityChange, $tx->quantity_change);

        $stock = InventoryStock::where('item_id', $intent->itemId)
            ->where('location_id', $intent->locationId)
            ->first();

        $this->assertNotNull($stock);
        $this->assertEquals(10.0000, $stock->physical_quantity);
    }

    public function test_it_handles_same_intent_replay()
    {
        $fixture = $this->createFixture();
        $intent = $this->createIntent($fixture);

        $tx1 = $this->coordinator->post($intent);
        $tx2 = $this->coordinator->post($intent);

        $this->assertEquals($tx1->id, $tx2->id);
        $this->assertEquals(1, InventoryTransaction::count());
        $this->assertEquals(10.0000, InventoryStock::first()->physical_quantity);
    }

    public function test_it_rejects_different_intent_collision()
    {
        $fixture = $this->createFixture();
        $key = (string) Str::ulid();

        $intent1 = $this->createIntent($fixture, $key, '10.0000');
        $intent2 = $this->createIntent($fixture, $key, '20.0000');

        $this->coordinator->post($intent1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Idempotency collision");

        $this->coordinator->post($intent2);
    }

    public function test_it_rejects_if_business_date_closed()
    {
        $fixture = $this->createFixture();
        $fixture[3]->update(['status' => PropertyBusinessDateStatusEnum::Closed, 'is_open' => null]);

        $intent = $this->createIntent($fixture);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Business date is closed or missing");

        $this->coordinator->post($intent);
    }

    public function test_it_rejects_if_financial_period_closed()
    {
        $fixture = $this->createFixture();
        $fixture[4]->update(['status' => FinancialPeriodStatusEnum::Closed]);

        $intent = $this->createIntent($fixture);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Financial period is closed or missing");

        $this->coordinator->post($intent);
    }

    public function test_it_rolls_back_transaction_on_controlled_failure()
    {
        $fixture = $this->createFixture();
        $intent = $this->createIntent($fixture);

        $mockRepo = \Mockery::mock(\Modules\Operations\Inventory\Repositories\InventoryStockRepository::class)->makePartial();
        $mockRepo->shouldReceive('updateBalance')
            ->once()
            ->andThrow(new RuntimeException('Injected repository failure'));

        $this->app->instance(\Modules\Operations\Inventory\Repositories\InventoryStockRepository::class, $mockRepo);
        $coordinator = app(InventoryPostingControlCoordinator::class);

        try {
            $coordinator->post($intent);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertEquals('Injected repository failure', $e->getMessage());
        }

        $this->assertEquals(0, InventoryTransaction::where('idempotency_key', $intent->idempotencyKey)->count(), 'Ledger transaction should be rolled back');
        $this->assertEquals(0, InventoryStock::where('property_id', $intent->propertyId)
            ->where('item_id', $intent->itemId)
            ->where('location_id', $intent->locationId)
            ->count(), 'Stock projection should be rolled back');
    }

    public function test_it_exhibits_zero_automatic_retry_behavior()
    {
        $fixture = $this->createFixture();
        $intent = $this->createIntent($fixture);

        $pdoException = new \PDOException('Deadlock test');
        $pdoException->errorInfo = ['40P01', 1234, 'Deadlock test'];
        $queryException = new \Illuminate\Database\QueryException('connection', 'sql', [], $pdoException);

        $mockRepo = \Mockery::mock(\Modules\Operations\Inventory\Repositories\InventoryTransactionRepository::class)->makePartial();
        $mockRepo->shouldReceive('appendControlled')
            ->once()
            ->andThrow($queryException);

        $this->app->instance(\Modules\Operations\Inventory\Repositories\InventoryTransactionRepository::class, $mockRepo);
        $coordinator = app(InventoryPostingControlCoordinator::class);

        try {
            $coordinator->post($intent);
            $this->fail('Expected exception was not thrown.');
        } catch (\Modules\Operations\Inventory\Exceptions\InventoryPostingRetryableException $e) {
            $this->assertEquals('DEADLOCK_DETECTED', $e->getReasonCode());
        }

        $this->assertEquals(0, InventoryTransaction::count(), 'Ledger transaction should be rolled back');
        $this->assertEquals(0, InventoryStock::count(), 'Stock projection should be rolled back');
    }
}
