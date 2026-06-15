<?php

namespace Tests\Feature\Finance\Treasury;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Models\Vendor;
use Illuminate\Database\QueryException;

class PaymentFoundationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_payment_lifecycle_and_approval_governance()
    {
        $property = $this->createProperty($this->createCompany());
        $creator = $this->createUser($property);
        $approver = $this->createUser($property);

        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'account_name' => 'Main Operating',
            'account_number' => 'ACC-12345',
            'bank_name' => 'Global Bank',
            'currency_code' => 'IDR',
        ]);

        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);
        $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'vendor_code' => 'V1', 'name' => 'V1']);

        // 1. Create Draft Payment
        $payment = VendorPayment::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $bankAccount->id,
            'payment_number' => 'PAY-001',
            'payment_date' => now(),
            'total_amount' => 5000,
            'status' => VendorPaymentStatusEnum::Draft->value,
        ]);

        $this->assertEquals(VendorPaymentStatusEnum::Draft, $payment->status);

        // 2. Submit for Approval
        $payment->update(['status' => VendorPaymentStatusEnum::PendingApproval->value]);
        $this->assertEquals(VendorPaymentStatusEnum::PendingApproval, $payment->status);

        // 3. Approval Governance (Creator != Approver logic is typically in the service, but we assert the model methods here)
        $this->actingAs($approver);
        $payment->markAsApproved();
        
        $this->assertEquals(VendorPaymentStatusEnum::Approved, $payment->fresh()->status);
        $this->assertEquals($approver->id, $payment->fresh()->approved_by);
        $this->assertNotNull($payment->fresh()->approved_at);

        // 4. Execution
        $payment->update(['status' => VendorPaymentStatusEnum::Executed->value]);
        $this->assertEquals(VendorPaymentStatusEnum::Executed, $payment->fresh()->status);
    }

    public function test_property_isolation_and_duplicate_prevention_on_payment()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        $bankAccountA = BankAccount::create(['property_id' => $propertyA->id, 'account_name' => 'A', 'account_number' => '123', 'bank_name' => 'Bank']);
        $bankAccountB = BankAccount::create(['property_id' => $propertyB->id, 'account_name' => 'B', 'account_number' => '123', 'bank_name' => 'Bank']);

        $categoryA = VendorCategory::create(['property_id' => $propertyA->id, 'name' => 'IT', 'category_code' => 'IT-A']);
        $categoryB = VendorCategory::create(['property_id' => $propertyB->id, 'name' => 'IT', 'category_code' => 'IT-B']);

        $vendorA = Vendor::create(['property_id' => $propertyA->id, 'vendor_category_id' => $categoryA->id, 'vendor_code' => 'VA', 'name' => 'VA']);
        $vendorB = Vendor::create(['property_id' => $propertyB->id, 'vendor_category_id' => $categoryB->id, 'vendor_code' => 'VB', 'name' => 'VB']);

        // Create Payment in Property A
        VendorPayment::create([
            'property_id' => $propertyA->id,
            'vendor_id' => $vendorA->id,
            'bank_account_id' => $bankAccountA->id,
            'payment_number' => 'PAY-ISO',
            'payment_date' => now(),
            'total_amount' => 100,
        ]);

        // Same Payment Number in Property B should succeed
        $paymentB = VendorPayment::create([
            'property_id' => $propertyB->id,
            'vendor_id' => $vendorB->id,
            'bank_account_id' => $bankAccountB->id,
            'payment_number' => 'PAY-ISO',
            'payment_date' => now(),
            'total_amount' => 200,
        ]);

        $this->assertNotNull($paymentB->id);

        $this->expectException(QueryException::class);

        // Same Payment Number in Property A should fail (Duplicate Prevention)
        VendorPayment::create([
            'property_id' => $propertyA->id,
            'vendor_id' => $vendorA->id,
            'bank_account_id' => $bankAccountA->id,
            'payment_number' => 'PAY-ISO',
            'payment_date' => now(),
            'total_amount' => 300,
        ]);
    }

    public function test_bank_account_validation_duplicate_prevention()
    {
        $property = $this->createProperty($this->createCompany());

        BankAccount::create([
            'property_id' => $property->id,
            'account_name' => 'Main',
            'account_number' => 'SAME_ACCOUNT',
            'bank_name' => 'Global Bank',
        ]);

        $this->expectException(QueryException::class);

        // Duplicate account number + bank name in same property should fail
        BankAccount::create([
            'property_id' => $property->id,
            'account_name' => 'Secondary',
            'account_number' => 'SAME_ACCOUNT',
            'bank_name' => 'Global Bank',
        ]);
    }
}
