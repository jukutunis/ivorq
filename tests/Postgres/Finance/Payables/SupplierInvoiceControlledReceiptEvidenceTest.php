<?php

namespace Tests\Postgres\Finance\Payables;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Payables\Services\SupplierInvoiceIntakeSourceService;
use Modules\Finance\Payables\Services\SupplierInvoiceRegistrationService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Contracts\ProcurementReceiptEvidenceProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class SupplierInvoiceControlledReceiptEvidenceTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private Property $property;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate([
            'name' => SupplierInvoiceRegistrationService::PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = CompanyFactory::new()->create(['is_active' => true]);
        $this->property = PropertyFactory::new()->create([
            'company_id' => $company->id,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $this->actor = UserFactory::new()->withProperty($this->property)->create(['is_active' => true]);
        $this->actor->givePermissionTo(SupplierInvoiceRegistrationService::PERMISSION);
    }

    public function test_posted_controlled_receipt_projects_registers_matches_and_remains_read_only(): void
    {
        $fixture = $this->makePurchaseOrder();
        $posted = $this->addControlledReceipt($fixture, 'POSTED', 4.000, 'GR-CONTROLLED-POSTED');
        $this->addControlledReceipt($fixture, 'DRAFT', 2.000, 'GR-CONTROLLED-DRAFT', false);
        $this->insertCollidingLegacyCapacityClaim($fixture, $posted['receipt_line_id'], 4.000);
        $before = $this->sourceCounts();

        $projection = app(SupplierInvoiceIntakeSourceService::class)->project($this->property->id);
        $source = collect($projection)->firstWhere('id', $fixture['purchase_order_id']);

        $this->assertNotNull($source);
        $this->assertSame('inventory_goods_receipt', $source['receipt_source_type']);
        $this->assertCount(1, $source['receipts']);
        $this->assertSame($posted['receipt_id'], $source['receipts'][0]['id']);
        $this->assertSame('Controlled Goods Receipt', $source['receipts'][0]['source_label']);
        $this->assertSame('4.000', $source['receipts'][0]['remaining_quantity']);

        $result = app(SupplierInvoiceRegistrationService::class)->registerFromSource([
            'property_id' => $this->property->id,
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $posted['receipt_id'],
            'receipt_source_type' => 'inventory_goods_receipt',
            'invoice_number' => 'SINV-CONTROLLED-1',
            'invoice_date' => '2026-11-05',
            'registration_idempotency_key' => 'controlled-register-1',
        ], $this->actor);

        $invoice = $result['invoice'];
        $this->assertSame('inventory_goods_receipt', $invoice->receipt_source_type);
        $this->assertSame($posted['evidence_hash'], $invoice->lines->first()->receipt_commercial_evidence_hash);
        $this->assertSame('Matched', $result['match']->status->value);
        $this->assertSame($posted['receipt_id'], $result['match']->goods_receipt_id);
        $this->assertSame($before, $this->sourceCounts(), 'Finance registration must not mutate receipt facts.');

        $afterProjection = app(SupplierInvoiceIntakeSourceService::class)->project($this->property->id);
        $afterSource = collect($afterProjection)->firstWhere('id', $fixture['purchase_order_id']);
        $this->assertCount(0, $afterSource['receipts'], 'An exhausted controlled receipt must leave projection.');

        $this->expectException(DomainException::class);
        app(SupplierInvoiceRegistrationService::class)->registerFromSource([
            'property_id' => $this->property->id,
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $posted['receipt_id'],
            'receipt_source_type' => 'legacy_receiving_document',
            'invoice_number' => 'SINV-CONTROLLED-1',
            'invoice_date' => '2026-11-05',
            'registration_idempotency_key' => 'controlled-register-1',
        ], $this->actor);
    }

    public function test_multiple_controlled_receipts_keep_distinct_capacity_and_ineligible_states_stay_hidden(): void
    {
        $fixture = $this->makePurchaseOrder();
        $first = $this->addControlledReceipt($fixture, 'POSTED', 3.000, 'GR-CONTROLLED-A');
        $second = $this->addControlledReceipt($fixture, 'POSTED', 5.000, 'GR-CONTROLLED-B');
        $this->addControlledReceipt($fixture, 'CONFIRMATION_PENDING', 1.000, 'GR-CONTROLLED-PENDING', false);

        $source = collect(app(SupplierInvoiceIntakeSourceService::class)->project($this->property->id))
            ->firstWhere('id', $fixture['purchase_order_id']);
        $this->assertCount(2, $source['receipts']);
        $this->assertEqualsCanonicalizing(
            [$first['receipt_id'], $second['receipt_id']],
            array_column($source['receipts'], 'id')
        );

        app(SupplierInvoiceRegistrationService::class)->registerFromSource([
            'property_id' => $this->property->id,
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $first['receipt_id'],
            'receipt_source_type' => 'inventory_goods_receipt',
            'invoice_number' => 'SINV-CONTROLLED-A',
            'invoice_date' => '2026-11-05',
            'registration_idempotency_key' => 'controlled-register-a',
        ], $this->actor);

        $remaining = collect(app(SupplierInvoiceIntakeSourceService::class)->project($this->property->id))
            ->firstWhere('id', $fixture['purchase_order_id']);
        $this->assertCount(1, $remaining['receipts']);
        $this->assertSame($second['receipt_id'], $remaining['receipts'][0]['id']);
        $this->assertSame('5.000', $remaining['receipts'][0]['remaining_quantity']);
    }

    public function test_mixed_receipt_universes_fail_closed_without_quantity_union(): void
    {
        $fixture = $this->makePurchaseOrder();
        $this->addControlledReceipt($fixture, 'POSTED', 2.000, 'GR-CONTROLLED-CONFLICT');
        $timestamp = now();

        DB::table('receiving_documents')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'vendor_id' => $fixture['vendor_id'],
            'purchase_order_id' => $fixture['purchase_order_id'],
            'grn_number' => 'GR-LEGACY-CONFLICT',
            'status' => 'approved',
            'received_at' => $timestamp,
            'received_by' => $this->actor->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('RECEIPT_SOURCE_CONFLICT_REQUIRES_MANUAL_RESOLUTION');
        app(ProcurementReceiptEvidenceProvider::class)->sourceType(
            $this->property->id,
            $fixture['purchase_order_id']
        );
    }

    public function test_controlled_receipt_with_wrong_uom_fails_closed(): void
    {
        $fixture = $this->makePurchaseOrder();
        $wrongUnitId = (string) Str::ulid();
        DB::table('inventory_units')->insert([
            'id' => $wrongUnitId,
            'property_id' => $this->property->id,
            'code' => 'WRONG-'.Str::random(6),
            'name' => 'Wrong Unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->addControlledReceipt(
            $fixture,
            'POSTED',
            1.000,
            'GR-CONTROLLED-WRONG-UOM',
            true,
            $wrongUnitId
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('receipt-line provenance is inconsistent');
        app(SupplierInvoiceIntakeSourceService::class)->project($this->property->id);
    }

    private function makePurchaseOrder(): array
    {
        $timestamp = now();
        $vendorCategoryId = (string) Str::ulid();
        $vendorId = (string) Str::ulid();
        $departmentId = (string) Str::ulid();
        $requestId = (string) Str::ulid();
        $purchaseOrderId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();
        $unitId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $purchaseOrderLineId = (string) Str::ulid();

        DB::table('vendor_categories')->insert([
            'id' => $vendorCategoryId,
            'property_id' => $this->property->id,
            'category_code' => 'VC-'.Str::random(6),
            'name' => 'Controlled Receipt Vendor',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('vendors')->insert([
            'id' => $vendorId,
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $vendorCategoryId,
            'vendor_code' => 'VND-'.Str::random(6),
            'name' => 'Controlled Receipt Vendor',
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'is_approved' => true,
            'performance_score' => 0,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('departments')->insert([
            'id' => $departmentId,
            'property_id' => $this->property->id,
            'name' => 'Controlled Purchasing',
            'code' => 'PUR-'.Str::random(6),
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('purchase_requests')->insert([
            'id' => $requestId,
            'property_id' => $this->property->id,
            'request_no' => 'PR-'.Str::random(8),
            'department_id' => $departmentId,
            'requester_id' => $this->actor->id,
            'required_date' => '2026-11-05',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'estimated_total' => 100,
            'status' => 'APPROVED',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('purchase_orders')->insert([
            'id' => $purchaseOrderId,
            'property_id' => $this->property->id,
            'po_no' => 'PO-'.Str::random(8),
            'vendor_id' => $vendorId,
            'purchase_request_id' => $requestId,
            'issue_date' => '2026-11-05',
            'expected_delivery_date' => '2026-11-06',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'received_total' => 0,
            'status' => 'ISSUED',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_categories')->insert([
            'id' => $categoryId,
            'property_id' => $this->property->id,
            'name' => 'Controlled Category',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_units')->insert([
            'id' => $unitId,
            'property_id' => $this->property->id,
            'code' => 'EA-'.Str::random(6),
            'name' => 'Each',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_items')->insert([
            'id' => $itemId,
            'property_id' => $this->property->id,
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Controlled Item',
            'category_id' => $categoryId,
            'inventory_type' => 'stock',
            'criticality' => 'low',
            'is_batch_tracked' => false,
            'is_expiry_tracked' => false,
            'weighted_average_cost' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_locations')->insert([
            'id' => $locationId,
            'property_id' => $this->property->id,
            'name' => 'Controlled Store',
            'type' => 'storeroom',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('purchase_order_lines')->insert([
            'id' => $purchaseOrderLineId,
            'purchase_order_id' => $purchaseOrderId,
            'inventory_item_id' => $itemId,
            'description' => 'Controlled Item',
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'invoiced_quantity' => 0,
            'receiving_tolerance_percent' => 0,
            'unit_id' => $unitId,
            'unit_cost' => 10,
            'line_total' => 100,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('purchase_order_receipt_source_authorities')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'purchase_order_id' => $purchaseOrderId,
            'source_type' => 'inventory_goods_receipt',
            'binding_reason' => 'test_controlled_default',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'vendor_id' => $vendorId,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_line_id' => $purchaseOrderLineId,
            'unit_id' => $unitId,
            'item_id' => $itemId,
            'location_id' => $locationId,
        ];
    }

    private function addControlledReceipt(
        array $fixture,
        string $status,
        float $quantity,
        string $number,
        bool $withEvidence = true,
        ?string $lineUnitId = null,
    ): array {
        $timestamp = now();
        $receiptId = (string) Str::ulid();
        $receiptLineId = (string) Str::ulid();
        $evidenceHash = hash('sha256', $receiptLineId.'|'.$quantity.'|10.00');

        DB::table('goods_receipts')->insert([
            'id' => $receiptId,
            'property_id' => $this->property->id,
            'purchase_order_id' => $fixture['purchase_order_id'],
            'receipt_number' => $number,
            'status' => $status,
            'received_at' => $timestamp,
            'posted_at' => $status === 'POSTED' ? $timestamp : null,
            'received_by' => $this->actor->id,
            'created_by' => $this->actor->id,
            'created_at' => $timestamp,
        ]);
        DB::table('goods_receipt_lines')->insert([
            'id' => $receiptLineId,
            'goods_receipt_id' => $receiptId,
            'property_id' => $this->property->id,
            'purchase_order_line_id' => $fixture['purchase_order_line_id'],
            'inventory_item_id' => $fixture['item_id'],
            'inventory_location_id' => $fixture['location_id'],
            'inventory_unit_id' => $lineUnitId ?? $fixture['unit_id'],
            'received_quantity' => $quantity,
            'idempotency_key' => 'receipt-line-'.$receiptLineId,
            'created_by' => $this->actor->id,
            'created_at' => $timestamp,
        ]);

        if ($withEvidence) {
            DB::table('goods_receipt_line_commercial_evidences')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'goods_receipt_id' => $receiptId,
                'goods_receipt_line_id' => $receiptLineId,
                'purchase_order_id' => $fixture['purchase_order_id'],
                'purchase_order_line_id' => $fixture['purchase_order_line_id'],
                'inventory_item_id' => $fixture['item_id'],
                'inventory_unit_id' => $fixture['unit_id'],
                'property_base_currency_code_snapshot' => 'IDR',
                'purchase_order_currency_code_snapshot' => 'IDR',
                'purchase_order_unit_cost_snapshot' => 10,
                'purchase_order_exchange_rate_snapshot' => 1,
                'commercial_evidence_hash' => $evidenceHash,
                'captured_at' => $timestamp,
                'created_by' => $this->actor->id,
                'created_at' => $timestamp,
            ]);
        }

        return [
            'receipt_id' => $receiptId,
            'receipt_line_id' => $receiptLineId,
            'evidence_hash' => $evidenceHash,
        ];
    }

    private function sourceCounts(): array
    {
        return collect([
            'goods_receipts',
            'goods_receipt_lines',
            'goods_receipt_line_commercial_evidences',
            'receiving_documents',
            'receiving_lines',
            'inventory_transactions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    private function insertCollidingLegacyCapacityClaim(array $fixture, string $receiptLineId, float $quantity): void
    {
        $invoiceId = (string) Str::ulid();
        $timestamp = now();
        DB::table('vendor_invoices')->insert([
            'id' => $invoiceId,
            'property_id' => $this->property->id,
            'vendor_id' => $fixture['vendor_id'],
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => (string) Str::ulid(),
            'receipt_source_type' => 'legacy_receiving_document',
            'invoice_number' => 'SINV-LEGACY-ID-COLLISION',
            'invoice_date' => '2026-11-05',
            'currency_code' => 'IDR',
            'status' => 'REGISTERED',
            'subtotal' => $quantity * 10,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'grand_total' => $quantity * 10,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('vendor_invoice_lines')->insert([
            'id' => (string) Str::ulid(),
            'vendor_invoice_id' => $invoiceId,
            'purchase_order_line_id' => $fixture['purchase_order_line_id'],
            'goods_receipt_line_id' => $receiptLineId,
            'inventory_item_id' => $fixture['item_id'],
            'description' => 'Legacy collision proof only',
            'quantity' => $quantity,
            'unit_price' => 10,
            'line_total' => $quantity * 10,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
