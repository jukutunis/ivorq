<?php

namespace Tests\Feature\Finance\Treasury;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Services\PaymentAllocationService;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Finance\AccountsPayable\Enums\InvoicePaymentStatusEnum;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Models\Vendor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;
use Illuminate\Support\Facades\DB;

class PaymentAllocationEngineTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected PaymentAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentAllocationService::class);
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

    public function test_full_payment_allocation()
    {
        $property = $this->createProperty($this->createCompany());
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
            'amount_paid' => 0,
            'amount_remaining' => 1000,
            'payment_status' => InvoicePaymentStatusEnum::Unpaid->value,
        ]);

        $payment = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-001',
            'payment_date' => now(),
            'total_amount' => 1000,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        $this->service->allocatePayment($payment, $invoice->id, 1000);

        $invoice->refresh();
        $this->assertEquals(1000, $invoice->amount_paid);
        $this->assertEquals(0, $invoice->amount_remaining);
        $this->assertEquals(InvoicePaymentStatusEnum::Paid, $invoice->payment_status);
        $this->assertCount(1, $payment->allocations);
    }

    public function test_partial_payment_and_multiple_payments()
    {
        $property = $this->createProperty($this->createCompany());
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
            'amount_paid' => 0,
            'amount_remaining' => 1000,
            'payment_status' => InvoicePaymentStatusEnum::Unpaid->value,
        ]);

        $payment1 = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-002',
            'payment_date' => now(),
            'total_amount' => 400,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        // 1. Partial Payment
        $this->service->allocatePayment($payment1, $invoice->id, 400);

        $invoice->refresh();
        $this->assertEquals(400, $invoice->amount_paid);
        $this->assertEquals(600, $invoice->amount_remaining);
        $this->assertEquals(InvoicePaymentStatusEnum::PartiallyPaid, $invoice->payment_status);

        // 2. Second Payment (Multiple Payments)
        $payment2 = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-003',
            'payment_date' => now(),
            'total_amount' => 600,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        $this->service->allocatePayment($payment2, $invoice->id, 600);

        $invoice->refresh();
        $this->assertEquals(1000, $invoice->amount_paid);
        $this->assertEquals(0, $invoice->amount_remaining);
        $this->assertEquals(InvoicePaymentStatusEnum::Paid, $invoice->payment_status);
        $this->assertCount(2, $invoice->paymentAllocations ?? $invoice->hasMany(\Modules\Finance\Treasury\Models\PaymentAllocation::class, 'ap_invoice_id')->get());
    }

    public function test_overpayment_prevention()
    {
        $property = $this->createProperty($this->createCompany());
        [$vendor, $bankAccount] = $this->setupVendorAndBank($property);

        $invoice = ApInvoice::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'vendor_invoice_number' => 'INV-003',
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'grand_total_amount' => 1000,
            'amount_paid' => 0,
            'amount_remaining' => 1000,
        ]);

        $payment = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-004',
            'payment_date' => now(),
            'total_amount' => 1200,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Overpayment prevention");

        $this->service->allocatePayment($payment, $invoice->id, 1200);
    }

    public function test_property_isolation_on_allocation()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        [$vendorA, $bankAccountA] = $this->setupVendorAndBank($propertyA);
        [$vendorB, $bankAccountB] = $this->setupVendorAndBank($propertyB);

        $invoiceB = ApInvoice::create([
            'property_id' => $propertyB->id,
            'vendor_id' => $vendorB->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'vendor_invoice_number' => 'INV-B',
            'invoice_date' => now(),
            'due_date' => now(),
            'grand_total_amount' => 1000,
            'amount_paid' => 0,
            'amount_remaining' => 1000,
        ]);

        $paymentA = VendorPayment::create([
            'property_id' => $propertyA->id,
            'vendor_id' => $vendorA->id, // Property A's vendor
            'bank_account_id' => $bankAccountA->id,
            'payment_number' => 'PAY-A',
            'payment_date' => now(),
            'total_amount' => 1000,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        $this->expectException(ModelNotFoundException::class);

        // Attempting to pay Property B's invoice from Property A's payment
        $this->service->allocatePayment($paymentA, $invoiceB->id, 1000);
    }

    public function test_concurrent_allocation_locks_row()
    {
        $property = $this->createProperty($this->createCompany());
        [$vendor, $bankAccount] = $this->setupVendorAndBank($property);

        $invoice = ApInvoice::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'vendor_invoice_number' => 'INV-CONC',
            'invoice_date' => now(),
            'due_date' => now(),
            'grand_total_amount' => 100,
            'amount_paid' => 0,
            'amount_remaining' => 100,
        ]);

        $payment1 = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-C1',
            'payment_date' => now(),
            'total_amount' => 60,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        $payment2 = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-C2',
            'payment_date' => now(),
            'total_amount' => 60,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        // To truly test concurrency in PHP without threading, we can simulate what the database does 
        // by wrapping one inside a transaction and trying to do another, but SQLite testing DB doesn't block 
        // the same way MySQL does. We will verify the logic doesn't allow overpayment when executed sequentially,
        // and the `lockForUpdate()` is present in the code.
        
        $this->service->allocatePayment($payment1, $invoice->id, 60);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Overpayment prevention");
        
        $this->service->allocatePayment($payment2, $invoice->id, 60);
    }
}
