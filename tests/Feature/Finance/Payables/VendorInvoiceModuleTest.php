<?php

namespace Tests\Feature\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class VendorInvoiceModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected User $user;
    protected Property $property;
    protected Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([\Database\Seeders\DatabaseSeeder::class]);

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->user = $this->createPropertyAdmin($this->property);
        
        $this->user->givePermissionTo([
            'payables.vendor-invoice.view',
            'payables.vendor-invoice.create',
            'payables.vendor-invoice.edit',
            'payables.vendor-invoice.cancel',
        ]);

        $category = $this->createVendorCategory($this->property);
        $this->vendor = $this->createVendor($this->property, $category, ['is_active' => true, 'is_approved' => true]);
    }

    public function test_can_create_vendor_invoice()
    {
        $payload = [
            'vendor_id' => $this->vendor->id,
            'invoice_number' => 'INV-2026-001',
            'invoice_date' => '2026-06-10',
            'due_date' => '2026-07-10',
            'status' => VendorInvoiceStatusEnum::Submitted->value,
            'lines' => [
                [
                    'description' => 'Test Item 1',
                    'quantity' => 10,
                    'unit_price' => 150.00,
                ],
                [
                    'description' => 'Test Item 2',
                    'quantity' => 5,
                    'unit_price' => 200.00,
                ]
            ],
            'tax_amount' => 250.00,
            'discount_amount' => 50.00,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payables/vendor-invoices', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.grand_total', 2700); // (10*150) + (5*200) + 250 - 50 = 1500 + 1000 + 200 = 2700

        $this->assertDatabaseHas('vendor_invoices', [
            'invoice_number' => 'INV-2026-001',
            'grand_total' => 2700,
        ]);

        $this->assertDatabaseCount('vendor_invoice_lines', 2);
    }

    public function test_cannot_create_duplicate_invoice_number_for_same_vendor()
    {
        VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'invoice_number' => 'INV-DUP-123'
        ]);

        $payload = [
            'vendor_id' => $this->vendor->id,
            'invoice_number' => 'INV-DUP-123',
            'invoice_date' => '2026-06-10',
            'lines' => [
                [
                    'description' => 'Test Item 1',
                    'quantity' => 10,
                    'unit_price' => 150.00,
                ]
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/payables/vendor-invoices', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_number']);
    }

    public function test_can_cancel_draft_or_submitted_invoice()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Submitted,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/cancel");

        $response->assertStatus(200);
        $this->assertDatabaseHas('vendor_invoices', [
            'id' => $invoice->id,
            'status' => VendorInvoiceStatusEnum::Cancelled->value,
        ]);
    }
}
