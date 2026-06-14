<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Carbon\Carbon;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Events\InventoryAdjustmentPosted;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Services\VariancePostingEngine;

class VariancePostingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected $property;
    protected $item;
    protected $location;
    protected $engine;
    protected $assetAccount;
    protected $expenseAccount;
    protected $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        $this->property = Property::first();
        
        $category = \Modules\Operations\Inventory\Models\InventoryCategory::first() ?? \Modules\Operations\Inventory\Models\InventoryCategory::create([
            'property_id' => $this->property->id,
            'name' => 'Test Category',
            'is_active' => true,
        ]);

        $this->item = InventoryItem::first() ?? InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'inventory_type' => 'Stock',
            'name' => 'Test Item',
            'sku' => 'TEST-SKU-' . uniqid(),
            'is_active' => true,
        ]);
        
        $this->location = InventoryLocation::first() ?? InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Main Store',
            'is_active' => true,
        ]);

        $this->assetAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '1000',
            'name' => 'Inventory Asset',
            'account_type' => AccountTypeEnum::Asset->value,
            'account_category' => 'CurrentAsset',
            'normal_balance' => 'Debit',
            'is_active' => true,
            'is_cash_equivalent' => false,
        ]);

        $this->expenseAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '5000',
            'name' => 'Inventory Loss',
            'account_type' => AccountTypeEnum::Expense->value,
            'account_category' => 'Expense',
            'normal_balance' => 'Debit',
            'is_active' => true,
            'is_cash_equivalent' => false,
        ]);

        $this->revenueAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '4000',
            'name' => 'Inventory Gain',
            'account_type' => AccountTypeEnum::Revenue->value,
            'account_category' => 'Revenue',
            'normal_balance' => 'Credit',
            'is_active' => true,
            'is_cash_equivalent' => false,
        ]);

        $this->actingAs(User::first());
        
        $this->engine = app(VariancePostingEngine::class);
    }

    private function setupValidMappings()
    {
        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->assetAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN->value,
            'account_id' => $this->revenueAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $this->property->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS->value,
            'account_id' => $this->expenseAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    private function createTransaction(TransactionTypeEnum $type, float $qtyChange, float $totalCost): InventoryTransaction
    {
        return clone InventoryTransaction::create([
            'property_id'      => $this->property->id,
            'item_id'          => $this->item->id,
            'location_id'      => $this->location->id,
            'transaction_type' => $type->value,
            'quantity_before'  => 10,
            'quantity_change'  => $qtyChange,
            'quantity_after'   => 10 + $qtyChange,
            'total_cost'       => $totalCost,
            'posted_at'        => now(),
            'posted_by'        => User::first()->id,
        ]);
    }

    public function test_positive_variance()
    {
        $this->setupValidMappings();

        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentIn, 5, 50);
        
        $this->engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        $this->assertNotNull($candidate);
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        $this->assertCount(2, $candidate->lines);

        $debit = $candidate->lines->where('entry_type', EntryTypeEnum::DEBIT->value)->first();
        $credit = $candidate->lines->where('entry_type', EntryTypeEnum::CREDIT->value)->first();

        $this->assertEquals(OperationalIdentityEnum::INVENTORY, $debit->operational_identity);
        $this->assertEquals(OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN, $credit->operational_identity);
        $this->assertEquals(50, $debit->amount);
    }

    public function test_negative_variance()
    {
        $this->setupValidMappings();

        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentOut, -3, -30);
        
        $this->engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);

        $debit = $candidate->lines->where('entry_type', EntryTypeEnum::DEBIT->value)->first();
        $credit = $candidate->lines->where('entry_type', EntryTypeEnum::CREDIT->value)->first();

        $this->assertEquals(OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS, $debit->operational_identity);
        $this->assertEquals(OperationalIdentityEnum::INVENTORY, $credit->operational_identity);
        $this->assertEquals(30, $debit->amount); // Absolute amount
    }

    public function test_configuration_error_handling_missing_mapping()
    {
        // No mappings defined
        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentIn, 5, 50);
        
        $this->engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
        $this->assertNotNull($candidate->metadata['mapping_error']);
        $this->assertEquals('OperationalIdentityMappingNotFoundException', $candidate->metadata['mapping_error']['type']);
    }

    public function test_configuration_error_handling_validation_failure()
    {
        $this->setupValidMappings(); // Setup valid mappings first

        // Then overwrite the INVENTORY mapping to be invalid (Inventory -> Expense account)
        OperationalIdentityMapping::where('operational_identity', OperationalIdentityEnum::INVENTORY->value)->update([
            'account_id' => $this->expenseAccount->id, // Should be Asset
        ]);

        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentOut, -3, -30);
        
        $this->engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
        $this->assertEquals('OperationalIdentityValidationException', $candidate->metadata['mapping_error']['type']);
    }

    public function test_idempotency_and_replay_protection()
    {
        $this->setupValidMappings();

        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentIn, 5, 50);
        
        // Process first time
        $this->engine->process($transaction);
        $candidateCount = JournalCandidate::where('source_id', $transaction->id)->count();
        $this->assertEquals(1, $candidateCount);

        // Process second time (replay)
        $this->engine->process($transaction);
        
        $candidateCountReplay = JournalCandidate::where('source_id', $transaction->id)->count();
        $this->assertEquals(1, $candidateCountReplay); // Did not duplicate
        
        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
    }
    
    public function test_replay_after_fixing_configuration_error()
    {
        // Missing mapping causes error
        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentIn, 5, 50);
        $this->engine->process($transaction);
        
        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);

        // Fix mapping
        $this->setupValidMappings();

        // Replay successfully
        $this->engine->process($transaction);

        $candidate->refresh();
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        $this->assertNull($candidate->metadata['mapping_error']); // Error cleared
    }

    public function test_property_isolation()
    {
        $otherProperty = Property::skip(1)->first() ?? Property::factory()->create();

        // Setup mappings for OTHER property
        OperationalIdentityMapping::create([
            'property_id' => $otherProperty->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->assetAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentIn, 5, 50);
        
        $this->engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        // Fails because mapping exists in other property, not this one
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
    }
}
