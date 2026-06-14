<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReevaluationService;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;

class JournalCandidateReevaluationTest extends TestCase
{
    use RefreshDatabase;

    protected $service;
    protected $property;
    protected $item;
    protected $location;
    protected $assetAccount;
    protected $expenseAccount;
    protected $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        $this->actingAs(User::first());
        $this->service = app(JournalCandidateReevaluationService::class);

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
            'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS->value,
            'account_id' => $this->expenseAccount->id,
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
    }

    private function createTransaction(TransactionTypeEnum $type, float $quantity, float $totalCost): InventoryTransaction
    {
        return clone InventoryTransaction::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'transaction_type' => $type->value,
            'quantity_before' => 10,
            'quantity_change' => $quantity,
            'quantity_after' => 10 + $quantity,
            'unit_cost' => abs($totalCost / $quantity),
            'total_cost' => $totalCost,
            'reference_type' => 'StockOpname',
            'reference_id' => '123',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ]);
    }

    public function test_mapping_repaired()
    {
        // 1. Create transaction without mappings => Should result in CONFIGURATION_ERROR
        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentOut, -5, -50);
        $engine = app(\Modules\Finance\GeneralLedger\Services\VariancePostingEngine::class);
        $engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);

        // 2. Repair mapping
        $this->setupValidMappings();

        // 3. Re-evaluate
        $reevaluated = $this->service->reevaluate($candidate->id);

        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $reevaluated->status);
        $this->assertCount(2, $reevaluated->lines);
    }

    public function test_validation_repaired()
    {
        // 1. Create invalid mapping (Inventory mapped to Expense)
        $this->setupValidMappings();
        OperationalIdentityMapping::where('operational_identity', OperationalIdentityEnum::INVENTORY->value)->update([
            'account_id' => $this->expenseAccount->id
        ]);

        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentIn, 5, 50);
        $engine = app(\Modules\Finance\GeneralLedger\Services\VariancePostingEngine::class);
        $engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);

        // 2. Repair mapping to valid asset account
        OperationalIdentityMapping::where('operational_identity', OperationalIdentityEnum::INVENTORY->value)->update([
            'account_id' => $this->assetAccount->id
        ]);

        // 3. Re-evaluate
        $reevaluated = $this->service->reevaluate($candidate->id);

        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $reevaluated->status);
    }

    public function test_still_failing()
    {
        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentOut, -5, -50);
        $engine = app(\Modules\Finance\GeneralLedger\Services\VariancePostingEngine::class);
        $engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        // Don't repair anything. Re-evaluate.
        $reevaluated = $this->service->reevaluate($candidate->id);

        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $reevaluated->status);
        $this->assertNotNull($reevaluated->last_reevaluation_error);
    }

    public function test_audit_tracking()
    {
        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentOut, -5, -50);
        $engine = app(\Modules\Finance\GeneralLedger\Services\VariancePostingEngine::class);
        $engine->process($transaction);

        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        $this->assertEquals(0, $candidate->reevaluation_count);

        $this->service->reevaluate($candidate->id);
        $candidate->refresh();
        $this->assertEquals(1, $candidate->reevaluation_count);
        $this->assertNotNull($candidate->reevaluated_by);
        $this->assertNotNull($candidate->reevaluated_at);

        $this->service->reevaluate($candidate->id);
        $candidate->refresh();
        $this->assertEquals(2, $candidate->reevaluation_count);
    }

    public function test_idempotency()
    {
        $this->setupValidMappings();
        $transaction = $this->createTransaction(TransactionTypeEnum::AdjustmentOut, -5, -50);
        $engine = app(\Modules\Finance\GeneralLedger\Services\VariancePostingEngine::class);
        
        // Force an error first by corrupting mapping temporarily
        OperationalIdentityMapping::where('operational_identity', OperationalIdentityEnum::INVENTORY->value)->update(['account_id' => $this->expenseAccount->id]);
        $engine->process($transaction);
        
        $candidate = JournalCandidate::where('source_id', $transaction->id)->first();
        
        // Repair
        OperationalIdentityMapping::where('operational_identity', OperationalIdentityEnum::INVENTORY->value)->update(['account_id' => $this->assetAccount->id]);

        // First click
        $reevaluated = $this->service->reevaluate($candidate->id);
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $reevaluated->status);
        $this->assertCount(2, $reevaluated->lines);

        // Second click -> Should fail because it's no longer CONFIGURATION_ERROR
        $this->expectException(ValidationException::class);
        $this->service->reevaluate($candidate->id);
        
        // Ensure candidates aren't duplicated
        $this->assertEquals(1, JournalCandidate::count());
    }

    public function test_property_isolation()
    {
        $otherProperty = Property::skip(1)->first() ?? Property::create([
            'name' => 'Other Property',
            'is_active' => true,
        ]);
        
        // Create candidate in other property
        $candidate = JournalCandidate::create([
            'property_id' => $otherProperty->id,
            'source_type' => 'InventoryTransaction',
            'source_id' => '123',
            'posting_event' => 'InventoryAdjustmentVariance',
            'status' => JournalCandidateStatusEnum::CONFIGURATION_ERROR->value,
            'candidate_date' => now(),
            'description' => 'Test',
        ]);

        // Attempting to re-evaluate it with a transaction that doesn't exist
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->reevaluate($candidate->id);
    }
}
