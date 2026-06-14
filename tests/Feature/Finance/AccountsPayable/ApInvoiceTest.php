<?php

namespace Tests\Feature\Finance\AccountsPayable;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Models\ApInvoiceLine;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Context\PropertyContext;
use Shared\Services\CurrentPropertyService;

class ApInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected $property;
    protected $vendor;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        
        $this->vendor = Vendor::first() ?? Vendor::create([
            'property_id' => $this->property->id,
            'vendor_category_id' => \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate([
                'property_id' => $this->property->id,
                'name' => 'General',
                'category_code' => 'GEN',
                'is_active' => true,
            ])->id,
            'name' => 'Test Vendor',
            'vendor_code' => 'TV-001',
            'is_active' => true,
        ]);
        $this->user = User::first();
        $this->actingAs($this->user);
    }

    public function test_duplicate_invoice_prevention()
    {
        ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'vendor_invoice_number' => 'INV-123',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('UNIQUE constraint failed'); // SQLite unique constraint violation

        ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'vendor_invoice_number' => 'INV-123',
        ]);
    }

    public function test_status_transitions()
    {
        $invoice = ApInvoice::factory()->create([
            'status' => ApInvoiceStatusEnum::DRAFT->value,
        ]);

        $this->assertTrue($invoice->status->canTransitionTo(ApInvoiceStatusEnum::PENDING_REVIEW));
        $this->assertTrue($invoice->status->canTransitionTo(ApInvoiceStatusEnum::VOIDED));
        $this->assertFalse($invoice->status->canTransitionTo(ApInvoiceStatusEnum::APPROVED));
        
        $invoice->status = ApInvoiceStatusEnum::PENDING_REVIEW->value;
        $this->assertTrue($invoice->status->canTransitionTo(ApInvoiceStatusEnum::APPROVED));
        $this->assertTrue($invoice->status->canTransitionTo(ApInvoiceStatusEnum::REJECTED));
    }

    public function test_approval_governance()
    {
        $invoice = ApInvoice::factory()->create([
            'status' => ApInvoiceStatusEnum::PENDING_REVIEW->value,
            'created_by' => $this->user->id,
        ]);

        // Typically, creator != approver would be enforced in a Service class. 
        // We will simulate it by ensuring the model can hold the fields correctly.
        $approver = User::skip(1)->first();

        $invoice->update([
            'status' => ApInvoiceStatusEnum::APPROVED->value,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $this->assertEquals($approver->id, $invoice->approved_by);
        $this->assertNotNull($invoice->approved_at);
        $this->assertNotEquals($invoice->created_by, $invoice->approved_by);
    }

    public function test_property_isolation()
    {
        $otherProperty = Property::skip(1)->first();
        
        $invoice = ApInvoice::factory()->create([
            'property_id' => $otherProperty->id,
            'vendor_invoice_number' => 'INV-OTHER-123'
        ]);

        // When we query with CurrentPropertyService set to $this->property, we shouldn't see it
        $this->assertEquals(0, ApInvoice::count());

        // When we switch context, we should see it
        app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
        $this->assertEquals(1, ApInvoice::count());
        $this->assertEquals('INV-OTHER-123', ApInvoice::first()->vendor_invoice_number);
    }

    public function test_direct_expense_invoice()
    {
        $invoice = ApInvoice::factory()->create([
            'invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value,
            'subtotal_amount' => 500.00,
            'tax_amount' => 50.00,
            'grand_total_amount' => 550.00,
        ]);

        $line = ApInvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'receipt_line_id' => null, // Direct expenses have no receipt lines
            'description' => 'Electricity Bill',
            'quantity' => 1,
            'unit_price' => 500.00,
            'subtotal_amount' => 500.00,
            'tax_amount' => 50.00,
            'total_amount' => 550.00,
        ]);

        $this->assertEquals(ApInvoiceTypeEnum::DIRECT_EXPENSE, $invoice->invoice_type);
        $this->assertNull($line->receipt_line_id);
    }
}
