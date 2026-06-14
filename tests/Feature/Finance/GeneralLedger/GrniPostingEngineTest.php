<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Services\GrniPostingEngine;
use Modules\Operations\Inventory\Events\InventoryReceiptPosted;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReevaluationService;
use Shared\Services\CurrentPropertyService;

class GrniPostingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected $property;
    protected $item;
    protected $location;
    protected $engine;
    protected $assetAccount;
    protected $liabilityAccount;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        
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

        $this->liabilityAccount = Account::create([
            'property_id' => $this->property->id,
            'code' => '2000',
            'name' => 'GRNI Control',
            'account_type' => AccountTypeEnum::Liability->value,
            'account_category' => 'CurrentLiability',
            'normal_balance' => 'Credit',
            'is_active' => true,
            'is_cash_equivalent' => false,
        ]);

        $this->actingAs(User::first());
        
        $this->engine = app(GrniPostingEngine::class);
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
            'operational_identity' => OperationalIdentityEnum::GRNI_RECEIPT->value,
            'account_id' => $this->liabilityAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    private function createReceipt(): InventoryReceipt
    {
        $receipt = InventoryReceipt::create([
            'property_id' => $this->property->id,
            'receipt_number' => 'RC-001',
            'supplier_name' => 'Test Vendor',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ]);

        InventoryReceiptLine::create([
            'receipt_id' => $receipt->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'unit_cost' => 5,
            'total_cost' => 50,
        ]);

        return $receipt->load('lines');
    }

    public function test_successful_generation()
    {
        $this->setupValidMappings();

        $receipt = $this->createReceipt();
        
        $this->engine->handle(new InventoryReceiptPosted($receipt));

        $candidate = JournalCandidate::where('source_id', $receipt->id)->first();
        
        $this->assertNotNull($candidate);
        if ($candidate->status === JournalCandidateStatusEnum::CONFIGURATION_ERROR) { dump($candidate->metadata); } $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        $this->assertCount(2, $candidate->lines);

        $debit = $candidate->lines->where('entry_type', EntryTypeEnum::DEBIT->value)->first();
        $credit = $candidate->lines->where('entry_type', EntryTypeEnum::CREDIT->value)->first();

        $this->assertEquals(OperationalIdentityEnum::INVENTORY, $debit->operational_identity);
        $this->assertEquals(OperationalIdentityEnum::GRNI_RECEIPT, $credit->operational_identity);
        $this->assertEquals(50, $debit->amount);
        $this->assertEquals(50, $credit->amount);
    }

    public function test_missing_mapping()
    {
        $receipt = $this->createReceipt();
        
        $this->engine->handle(new InventoryReceiptPosted($receipt));

        $candidate = JournalCandidate::where('source_id', $receipt->id)->first();
        
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
        $this->assertNotNull($candidate->metadata['mapping_error']);
    }

    public function test_idempotency()
    {
        $this->setupValidMappings();

        $receipt = $this->createReceipt();
        
        $this->engine->handle(new InventoryReceiptPosted($receipt));
        $this->engine->handle(new InventoryReceiptPosted($receipt)); // Replay

        $count = JournalCandidate::where('source_id', $receipt->id)->count();
        $this->assertEquals(1, $count);
        
        $candidate = JournalCandidate::where('source_id', $receipt->id)->first();
        if ($candidate->status === JournalCandidateStatusEnum::CONFIGURATION_ERROR) { dump($candidate->metadata); } $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
    }

    public function test_property_isolation()
    {
        $otherProperty = Property::skip(1)->first() ?? Property::create([
            'name' => 'Other',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $otherProperty->id,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->assetAccount->id,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        $receipt = $this->createReceipt();
        $this->engine->handle(new InventoryReceiptPosted($receipt));

        $candidate = JournalCandidate::where('source_id', $receipt->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
    }

    public function test_reevaluation_compatibility()
    {
        $receipt = $this->createReceipt();
        $this->engine->handle(new InventoryReceiptPosted($receipt));

        $candidate = JournalCandidate::where('source_id', $receipt->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);

        $this->setupValidMappings();

        $reevalService = app(JournalCandidateReevaluationService::class);
        $reevaluated = $reevalService->reevaluate($candidate->id);

        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $reevaluated->status);
        $this->assertCount(2, $reevaluated->lines);
    }
}
