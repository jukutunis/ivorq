<?php

namespace Tests\Feature\Finance\AccountsPayable;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\AccountsPayable\Services\ApPostingEngine;
use Modules\Finance\AccountsPayable\Services\InvoiceVarianceCalculationEngine;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Models\ApInvoiceLine;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;

class ApPostingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected $engine;
    protected $property;
    protected $vendor;
    protected $receiptLine;
    protected $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        $this->property = Property::first();
        
        $this->vendor = Vendor::firstOrCreate([
            'property_id' => $this->property->id,
            'vendor_category_id' => VendorCategory::firstOrCreate([
                'property_id' => $this->property->id,
                'name' => 'General',
                'category_code' => 'GEN',
                'is_active' => true,
            ])->id,
            'name' => 'Test Vendor',
            'vendor_code' => 'TV-001',
            'is_active' => true,
        ]);

        $receipt = ReceivingDocument::first() ?? ReceivingDocument::forceCreate([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'REC-001',
            'status' => 'approved',
        ]);

        $item = InventoryItem::first() ?? InventoryItem::forceCreate([
            'property_id' => $this->property->id,
            'category_id' => \Modules\Operations\Inventory\Models\InventoryCategory::first()->id ?? \Modules\Operations\Inventory\Models\InventoryCategory::forceCreate([
                'property_id' => $this->property->id,
                'name' => 'General Items',
            ])->id,
            'name' => 'Test Item',
            'inventory_type' => 'stock',
            'sku' => 'TEST-001',
        ]);

        $location = InventoryLocation::first() ?? InventoryLocation::forceCreate([
            'property_id' => $this->property->id,
            'name' => 'Test Location',
        ]);

        $this->receiptLine = ReceivingLine::forceCreate([
            'receiving_document_id' => $receipt->id,
            'inventory_item_id' => $item->id,
            'destination_location_id' => $location->id,
            'description' => 'Test Item',
            'received_quantity' => 10,
            'unit_cost' => 50.00,
            'line_total' => 500.00,
        ]);

        $this->engine = new ApPostingEngine(
            new InvoiceVarianceCalculationEngine(),
            app(OperationalIdentityMappingService::class),
            app(OperationalIdentityValidationService::class)
        );

        $this->setupMappings();
    }

    private function setupMappings()
    {
        $liabilityAccount = Account::forceCreate([
            'property_id' => $this->property->id,
            'code' => '2000',
            'name' => 'Liability',
            'account_type' => AccountTypeEnum::Liability->value,
            'account_category' => \Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum::CurrentLiability->value,
            'normal_balance' => \Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum::Credit->value,
            'is_active' => true,
        ]);

        $expenseAccount = Account::forceCreate([
            'property_id' => $this->property->id,
            'code' => '5000',
            'name' => 'Expense',
            'account_type' => AccountTypeEnum::Expense->value,
            'account_category' => \Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum::Expense->value,
            'normal_balance' => \Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum::Debit->value,
            'is_active' => true,
        ]);

        $identities = [
            OperationalIdentityEnum::GRNI_ACCRUAL,
            OperationalIdentityEnum::AP_INVOICE_VARIANCE,
            OperationalIdentityEnum::VENDOR_TAX,
            OperationalIdentityEnum::AP_CONTROL,
            OperationalIdentityEnum::OPERATIONAL_EXPENSE,
        ];

        foreach ($identities as $identity) {
            OperationalIdentityMapping::forceCreate([
                'property_id' => $this->property->id,
                'operational_identity' => $identity->value,
                'account_id' => in_array($identity, [OperationalIdentityEnum::AP_CONTROL, OperationalIdentityEnum::GRNI_ACCRUAL, OperationalIdentityEnum::VENDOR_TAX]) 
                    ? $liabilityAccount->id 
                    : $expenseAccount->id,
                'effective_from' => '2020-01-01',
                'is_active' => true,
            ]);
        }
    }

    public function test_grni_matched_posting()
    {
        // Receipt cost: $500. Invoice total: $500. Tax: $50. Grand Total: $550.
        $invoice = ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::GRNI_MATCHED->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'subtotal_amount' => 500.00,
            'tax_amount' => 50.00,
            'grand_total_amount' => 550.00,
        ]);

        ApInvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'receipt_line_id' => $this->receiptLine->id,
            'quantity' => 10,
            'unit_price' => 50.00,
            'subtotal_amount' => 500.00,
            'tax_amount' => 50.00,
            'total_amount' => 550.00,
        ]);

        $this->engine->processInvoice($invoice);

        $candidate = JournalCandidate::where('source_id', $invoice->id)->first();
        $this->assertNotNull($candidate);
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);

        $this->assertEquals(3, $candidate->lines()->count()); // GRNI, TAX, AP_CONTROL
        
        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::GRNI_ACCRUAL->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => 500.00,
        ]);

        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::VENDOR_TAX->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => 50.00,
        ]);

        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
            'entry_type' => EntryTypeEnum::CREDIT->value,
            'amount' => 550.00,
        ]);
        
        $invoice->refresh();
        $this->assertEquals(ApInvoiceStatusEnum::POSTED, $invoice->status);
    }

    public function test_direct_expense_posting()
    {
        $invoice = ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'subtotal_amount' => 100.00,
            'tax_amount' => 0.00,
            'grand_total_amount' => 100.00,
        ]);

        $this->engine->processInvoice($invoice);

        $candidate = JournalCandidate::where('source_id', $invoice->id)->first();
        $this->assertNotNull($candidate);
        
        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::OPERATIONAL_EXPENSE->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => 100.00,
        ]);

        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
            'entry_type' => EntryTypeEnum::CREDIT->value,
            'amount' => 100.00,
        ]);
    }

    public function test_variance_posting()
    {
        // Receipt cost: $500. Invoice Unit Price: $60 (total: $600). Tax: $0.
        $invoice = ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::GRNI_MATCHED->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'subtotal_amount' => 600.00,
            'tax_amount' => 0.00,
            'grand_total_amount' => 600.00,
        ]);

        ApInvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'receipt_line_id' => $this->receiptLine->id,
            'quantity' => 10,
            'unit_price' => 60.00,
            'subtotal_amount' => 600.00,
            'tax_amount' => 0.00,
            'total_amount' => 600.00,
        ]);

        $this->engine->processInvoice($invoice);

        $candidate = JournalCandidate::where('source_id', $invoice->id)->first();
        $this->assertNotNull($candidate);

        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::GRNI_ACCRUAL->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => 500.00,
        ]);

        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::AP_INVOICE_VARIANCE->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => 100.00,
        ]);

        $this->assertDatabaseHas('journal_candidate_lines', [
            'journal_candidate_id' => $candidate->id,
            'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
            'entry_type' => EntryTypeEnum::CREDIT->value,
            'amount' => 600.00,
        ]);
    }

    public function test_duplicate_prevention()
    {
        $invoice = ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
        ]);

        $this->engine->processInvoice($invoice);
        $this->engine->processInvoice($invoice); // Should ignore

        $this->assertEquals(1, JournalCandidate::where('source_id', $invoice->id)->count());
    }

    public function test_reevaluation_support_with_mapping_error()
    {
        // Delete a mapping to cause a configuration error
        OperationalIdentityMapping::where('operational_identity', OperationalIdentityEnum::AP_CONTROL->value)->delete();

        $invoice = ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'subtotal_amount' => 100.00,
            'tax_amount' => 0.00,
            'grand_total_amount' => 100.00,
        ]);

        $this->engine->processInvoice($invoice);

        $candidate = JournalCandidate::where('source_id', $invoice->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
        $this->assertNotNull($candidate->metadata['mapping_error']);
        
        // Ensure invoice doesn't get POSTED
        $invoice->refresh();
        $this->assertEquals(ApInvoiceStatusEnum::APPROVED, $invoice->status);
    }
}
