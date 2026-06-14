<?php

namespace Tests\Feature\Finance\AccountsPayable;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\AccountsPayable\Services\InvoiceMatchingService;
use Modules\Finance\AccountsPayable\Services\InvoiceVarianceCalculationEngine;
use Modules\Finance\AccountsPayable\Exceptions\InvoiceMatchingException;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Models\ApInvoiceLine;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;

class InvoiceMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;
    protected $property;
    protected $vendor;
    protected $receiptLine;
    protected $invoice;
    protected $invoiceLine;

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

        $receipt = InventoryReceipt::first() ?? InventoryReceipt::forceCreate([
            'property_id' => $this->property->id,
            'receipt_number' => 'REC-001',
            'status' => 'posted',
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

        $this->receiptLine = InventoryReceiptLine::create([
            'property_id' => $this->property->id,
            'receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'unit_cost' => 50.00,
            'line_total' => 500.00,
            'invoiced_quantity' => 0,
            'invoiced_amount' => 0,
        ]);

        $this->invoice = ApInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'invoice_type' => ApInvoiceTypeEnum::GRNI_MATCHED->value,
        ]);

        $this->invoiceLine = ApInvoiceLine::factory()->create([
            'invoice_id' => $this->invoice->id,
            'receipt_line_id' => $this->receiptLine->id,
            'quantity' => 10,
            'unit_price' => 50.00,
        ]);

        $this->service = new InvoiceMatchingService(new InvoiceVarianceCalculationEngine());
    }

    public function test_full_match()
    {
        $result = $this->service->matchLine($this->invoice, $this->invoiceLine);

        $this->assertEquals('FULL_MATCH', $result['match_type']);
        $this->assertEquals(500.00, $result['matched_amount']);
        $this->assertEquals(0.00, $result['variance_amount']);

        $this->receiptLine->refresh();
        $this->assertEquals(10, $this->receiptLine->invoiced_quantity);
        $this->assertEquals(500.00, $this->receiptLine->invoiced_amount);
    }

    public function test_partial_match()
    {
        $this->invoiceLine->update(['quantity' => 5]);

        $result = $this->service->matchLine($this->invoice, $this->invoiceLine);

        $this->assertEquals('PARTIAL_MATCH', $result['match_type']);
        $this->assertEquals(250.00, $result['matched_amount']);
        $this->assertEquals(0.00, $result['variance_amount']);

        $this->receiptLine->refresh();
        $this->assertEquals(5, $this->receiptLine->invoiced_quantity);
        $this->assertEquals(250.00, $this->receiptLine->invoiced_amount);
    }

    public function test_price_variance_over_invoice()
    {
        // Receipt cost: $50
        // Vendor charged: $60
        $this->invoiceLine->update(['unit_price' => 60.00]);

        $result = $this->service->matchLine($this->invoice, $this->invoiceLine);

        $this->assertEquals('FULL_MATCH', $result['match_type']); // Full quantity matched
        $this->assertEquals(500.00, $result['matched_amount']); // Original receipt value reversed
        $this->assertEquals(100.00, $result['variance_amount']); // Positive variance of $100

        $this->receiptLine->refresh();
        $this->assertEquals(10, $this->receiptLine->invoiced_quantity);
        $this->assertEquals(500.00, $this->receiptLine->invoiced_amount);
    }

    public function test_price_variance_under_invoice()
    {
        // Receipt cost: $50
        // Vendor charged: $45
        $this->invoiceLine->update(['unit_price' => 45.00]);

        $result = $this->service->matchLine($this->invoice, $this->invoiceLine);

        $this->assertEquals('FULL_MATCH', $result['match_type']);
        $this->assertEquals(500.00, $result['matched_amount']);
        $this->assertEquals(-50.00, $result['variance_amount']); // Negative variance of -$50
        $this->assertEquals(-10.00, $result['variance_percent']);

        $this->receiptLine->refresh();
        $this->assertEquals(10, $this->receiptLine->invoiced_quantity);
        $this->assertEquals(500.00, $this->receiptLine->invoiced_amount);
    }

    public function test_invoiced_quantity_exceeds_received()
    {
        $this->invoiceLine->update(['quantity' => 15]);

        $this->expectException(InvoiceMatchingException::class);
        $this->expectExceptionMessage("Invoiced quantity cannot exceed the received quantity.");

        $this->service->matchLine($this->invoice, $this->invoiceLine);
    }

    public function test_direct_expense_bypasses_matching()
    {
        $this->invoice->update(['invoice_type' => ApInvoiceTypeEnum::DIRECT_EXPENSE->value]);

        $result = $this->service->processInvoice($this->invoice);

        $this->assertEquals('bypassed', $result['status']);
    }

    public function test_property_mismatch_prevention()
    {
        $otherProperty = Property::skip(1)->first();
        $this->invoice->update(['property_id' => $otherProperty->id]);

        $this->expectException(InvoiceMatchingException::class);
        $this->expectExceptionMessage("Invoice and receipt must belong to the same property.");

        $this->service->matchLine($this->invoice, $this->invoiceLine);
    }
}
