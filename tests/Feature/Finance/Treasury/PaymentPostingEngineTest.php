<?php

namespace Tests\Feature\Finance\Treasury;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Models\PaymentAllocation;
use Modules\Finance\Treasury\Services\PaymentPostingEngine;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Foundation\Department\Models\Department;
use Exception;

class PaymentPostingEngineTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected PaymentPostingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(PaymentPostingEngine::class);
    }

    protected function setupVendorAndBank($property)
    {
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);
        $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'vendor_code' => 'V1', 'name' => 'V1']);

        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'account_name' => 'Main',
            'account_number' => '12345',
            'bank_name' => 'Bank',
        ]);

        return [$vendor, $bankAccount];
    }

    protected function createMappings($property)
    {
        $apAccount = Account::create([
            'property_id' => $property->id,
            'code' => '2000',
            'name' => 'AP Control',
            'account_type' => AccountTypeEnum::Liability->value,
            'account_category' => AccountCategoryEnum::CurrentLiability->value,
            'is_active' => true,
        ]);

        $bankAccountCoa = Account::create([
            'property_id' => $property->id,
            'code' => '1000',
            'name' => 'Cash in Bank',
            'account_type' => AccountTypeEnum::Asset->value,
            'account_category' => AccountCategoryEnum::CurrentAsset->value,
            'is_active' => true,
        ]);

        $bankFeeAccount = Account::create([
            'property_id' => $property->id,
            'code' => '5000',
            'name' => 'Bank Fees',
            'account_type' => AccountTypeEnum::Expense->value,
            'account_category' => AccountCategoryEnum::Expense->value,
            'is_active' => true,
        ]);

        $varianceAccount = Account::create([
            'property_id' => $property->id,
            'code' => '6000',
            'name' => 'Payment Variance',
            'account_type' => AccountTypeEnum::Expense->value,
            'account_category' => AccountCategoryEnum::Expense->value,
            'is_active' => true,
        ]);

        $costCenter = Department::create([
            'property_id' => $property->id,
            'code' => 'FIN',
            'name' => 'Finance',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $property->id,
            'operational_identity' => OperationalIdentityEnum::AP_PAYMENT->value,
            'account_id' => $apAccount->id,
            'effective_from' => '2020-01-01',
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $property->id,
            'operational_identity' => OperationalIdentityEnum::BANK_DISBURSEMENT->value,
            'account_id' => $bankAccountCoa->id,
            'effective_from' => '2020-01-01',
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $property->id,
            'operational_identity' => OperationalIdentityEnum::BANK_FEE->value,
            'account_id' => $bankFeeAccount->id,
            'effective_from' => '2020-01-01',
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $property->id,
            'operational_identity' => OperationalIdentityEnum::PAYMENT_VARIANCE->value,
            'account_id' => $varianceAccount->id,
            'effective_from' => '2020-01-01',
        ]);
    }

    public function test_full_payment_posting()
    {
        $property = $this->createProperty($this->createCompany());
        $this->createMappings($property);
        [$vendor, $bankAccount] = $this->setupVendorAndBank($property);

        $invoice = ApInvoice::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'vendor_invoice_number' => 'INV-001',
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'grand_total_amount' => 1000,
        ]);

        $payment = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-001',
            'payment_date' => now(),
            'total_amount' => 1000,
            'status' => VendorPaymentStatusEnum::Executed->value,
        ]);

        PaymentAllocation::create([
            'property_id' => $property->id,
            'vendor_payment_id' => $payment->id,
            'ap_invoice_id' => $invoice->id,
            'allocated_amount' => 1000,
        ]);

        $this->engine->processPayment($payment);

        $candidate = JournalCandidate::where('source_id', $payment->id)->first();
        
        $this->assertNotNull($candidate);
        if ($candidate->status === JournalCandidateStatusEnum::CONFIGURATION_ERROR) {
            dump($candidate->metadata['mapping_error']);
        }
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);
        
        $lines = $candidate->lines;
        $this->assertCount(2, $lines);
        
        $debit = $lines->where('entry_type', 'DEBIT')->first();
        $credit = $lines->where('entry_type', 'CREDIT')->first();

        $this->assertEquals(1000, $debit->amount);
        $this->assertEquals(OperationalIdentityEnum::AP_PAYMENT, $debit->operational_identity);

        $this->assertEquals(1000, $credit->amount);
        $this->assertEquals(OperationalIdentityEnum::BANK_DISBURSEMENT, $credit->operational_identity);
    }

    public function test_partial_payment_and_bank_fee()
    {
        $property = $this->createProperty($this->createCompany());
        $this->createMappings($property);
        [$vendor, $bankAccount] = $this->setupVendorAndBank($property);

        $invoice = ApInvoice::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'vendor_invoice_number' => 'INV-002',
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'grand_total_amount' => 1000,
        ]);

        $payment = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-002',
            'payment_date' => now(),
            'total_amount' => 500, // Total leaving bank is 500
            'bank_fee_amount' => 10,
            'status' => VendorPaymentStatusEnum::Executed->value,
        ]);

        // We allocated 490 to invoice. Total Debits = 490 + 10 (fee) = 500. Total Credit = 500. No variance.
        PaymentAllocation::create([
            'property_id' => $property->id,
            'vendor_payment_id' => $payment->id,
            'ap_invoice_id' => $invoice->id,
            'allocated_amount' => 490,
        ]);

        $this->engine->processPayment($payment);

        $candidate = JournalCandidate::where('source_id', $payment->id)->first();
        $this->assertEquals(JournalCandidateStatusEnum::PENDING_REVIEW, $candidate->status);

        $lines = $candidate->lines;
        $this->assertCount(3, $lines); // AP Payment, Bank Disbursement, Bank Fee
        
        $apDebit = $lines->where('operational_identity', OperationalIdentityEnum::AP_PAYMENT)->first();
        $feeDebit = $lines->where('operational_identity', OperationalIdentityEnum::BANK_FEE)->first();
        $bankCredit = $lines->where('operational_identity', OperationalIdentityEnum::BANK_DISBURSEMENT)->first();

        $this->assertEquals(490, $apDebit->amount);
        $this->assertEquals(10, $feeDebit->amount);
        $this->assertEquals(500, $bankCredit->amount);
    }

    public function test_configuration_error()
    {
        $property = $this->createProperty($this->createCompany());
        // Do NOT create mappings
        [$vendor, $bankAccount] = $this->setupVendorAndBank($property);

        $payment = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-ERR',
            'payment_date' => now(),
            'total_amount' => 1000,
            'status' => VendorPaymentStatusEnum::Executed->value,
        ]);

        $this->engine->processPayment($payment);

        $candidate = JournalCandidate::where('source_id', $payment->id)->first();
        
        $this->assertNotNull($candidate);
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
        $this->assertNotNull($candidate->metadata['mapping_error']);
    }

    public function test_duplicate_prevention()
    {
        $property = $this->createProperty($this->createCompany());
        $this->createMappings($property);
        [$vendor, $bankAccount] = $this->setupVendorAndBank($property);

        $payment = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-DUP',
            'payment_date' => now(),
            'total_amount' => 1000,
            'status' => VendorPaymentStatusEnum::Executed->value,
        ]);

        // Process twice
        $this->engine->processPayment($payment);
        $this->engine->processPayment($payment);

        $candidates = JournalCandidate::where('source_id', $payment->id)->get();
        
        // Ensure idempotency (only 1 candidate)
        $this->assertCount(1, $candidates);
    }

    public function test_property_isolation()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        // Mappings for A only
        $this->createMappings($propertyA);

        // Payment for B
        [$vendorB, $bankAccountB] = $this->setupVendorAndBank($propertyB);

        $paymentB = VendorPayment::create([
            'property_id' => $propertyB->id,
            'vendor_id' => $vendorB->id,
            'bank_account_id' => $bankAccountB->id,
            'payment_number' => 'PAY-B',
            'payment_date' => now(),
            'total_amount' => 1000,
            'status' => VendorPaymentStatusEnum::Executed->value,
        ]);

        $this->engine->processPayment($paymentB);

        $candidate = JournalCandidate::where('source_id', $paymentB->id)->first();
        
        // Property B has no mappings, should configuration error
        $this->assertEquals(JournalCandidateStatusEnum::CONFIGURATION_ERROR, $candidate->status);
        $this->assertEquals($propertyB->id, $candidate->property_id);
    }
}
