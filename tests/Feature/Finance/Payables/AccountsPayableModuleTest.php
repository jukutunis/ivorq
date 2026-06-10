<?php

namespace Tests\Feature\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Payables\Enums\AccountPayableStatusEnum;
use Modules\Finance\Payables\Enums\MatchExceptionEnum;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Modules\Finance\Payables\Models\AccountPayable;
use Modules\Finance\Payables\Models\ThreeWayMatch;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class AccountsPayableModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected User $user;
    protected Property $property;
    protected Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\DatabaseSeeder::class]);
        $this->seedPurchasingPermissions();

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->user = $this->createPropertyAdmin($this->property);

        $this->user->givePermissionTo([
            'payables.ap.view',
            'payables.ap.create',
            'payables.vendor-invoice.view', // needed for the generate endpoint
        ]);

        $category = $this->createVendorCategory($this->property);
        $this->vendor = $this->createVendor($this->property, $category, ['is_active' => true, 'is_approved' => true]);
    }

    public function test_can_generate_ap_from_matched_invoice()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Matched,
            'grand_total' => 1000.50,
            'invoice_number' => 'INV-2026-001',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap");

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', "1000.50");
        $response->assertJsonPath('data.status', AccountPayableStatusEnum::Open->value);
        $response->assertJsonPath('data.remarks', "Generated from Vendor Invoice INV-2026-001");

        $this->assertDatabaseHas('accounts_payables', [
            'vendor_invoice_id' => $invoice->id,
            'status' => AccountPayableStatusEnum::Open->value,
            'amount' => 1000.50,
            'outstanding_amount' => 1000.50,
        ]);
    }

    public function test_cannot_generate_ap_from_unmatched_invoice()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Submitted,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap");

        $response->assertStatus(400);
        $this->assertDatabaseMissing('accounts_payables', [
            'vendor_invoice_id' => $invoice->id,
        ]);
    }

    public function test_cannot_generate_ap_from_exception_invoice()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Submitted,
        ]);

        // Simulate an exception match
        ThreeWayMatch::create([
            'property_id' => $this->property->id,
            'vendor_invoice_id' => $invoice->id,
            'status' => MatchStatusEnum::Exception,
            'exception_code' => MatchExceptionEnum::MissingPurchaseOrder,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap");

        $response->assertStatus(400);
        $this->assertDatabaseMissing('accounts_payables', [
            'vendor_invoice_id' => $invoice->id,
        ]);
    }

    public function test_only_one_ap_per_invoice()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Matched,
        ]);

        // First call
        $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap")
            ->assertStatus(201);

        // Second call
        $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap")
            ->assertStatus(400);

        $this->assertDatabaseCount('accounts_payables', 1);
    }

    public function test_property_isolation_for_ap()
    {
        // Try to generate AP for an invoice that belongs to another property
        $otherCompany = $this->createCompany();
        $otherProperty = $this->createProperty($otherCompany);
        $otherVendorCategory = $this->createVendorCategory($otherProperty);
        $otherVendor = $this->createVendor($otherProperty, $otherVendorCategory);

        $invoice = VendorInvoice::factory()->create([
            'property_id' => $otherProperty->id,
            'vendor_id' => $otherVendor->id,
            'status' => VendorInvoiceStatusEnum::Matched,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap");

        $response->assertStatus(404);
    }

    public function test_ap_number_generation()
    {
        $invoice1 = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Matched,
        ]);

        $invoice2 = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Matched,
        ]);

        $res1 = $this->actingAs($this->user)->postJson("/api/v1/payables/vendor-invoices/{$invoice1->id}/generate-ap");
        $res2 = $this->actingAs($this->user)->postJson("/api/v1/payables/vendor-invoices/{$invoice2->id}/generate-ap");

        $year = date('Y');
        $res1->assertJsonPath('data.payable_no', "AP-{$year}-000001");
        $res2->assertJsonPath('data.payable_no', "AP-{$year}-000002");
    }

    public function test_audit_log_created()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Matched,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap");

        $response->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => AccountPayable::class,
            'event' => 'created',
        ]);
    }

    public function test_open_ap_outstanding_equals_invoice_total()
    {
        $invoice = VendorInvoice::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'status' => VendorInvoiceStatusEnum::Matched,
            'grand_total' => 2550.75,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payables/vendor-invoices/{$invoice->id}/generate-ap");

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', "2550.75");
        $response->assertJsonPath('data.outstanding_amount', "2550.75");
    }
}
