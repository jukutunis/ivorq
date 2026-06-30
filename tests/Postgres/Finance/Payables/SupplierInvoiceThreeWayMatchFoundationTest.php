<?php

namespace Tests\Postgres\Finance\Payables;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Finance\Payables\Enums\MatchExceptionEnum;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Services\SupplierInvoiceRegistrationService;
use Modules\Finance\Payables\Services\ThreeWayMatchingEngine;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class SupplierInvoiceThreeWayMatchFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;
    private User $actor;
    private SupplierInvoiceRegistrationService $service;
    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        Permission::firstOrCreate([
            'name' => SupplierInvoiceRegistrationService::PERMISSION,
            'guard_name' => 'web',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(SupplierInvoiceRegistrationService::PERMISSION);

        $this->service = app(SupplierInvoiceRegistrationService::class);
    }

    public function test_authorized_actor_registers_supplier_invoice_and_matched_result_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture);
        $controlledBefore = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);

        $result = $this->service->registerAndMatch($payload, $this->actor);
        $invoice = $result['invoice'];
        $match = $result['match'];

        $this->assertDatabaseHas('vendor_invoices', [
            'id' => $invoice->id,
            'property_id' => $this->property->id,
            'vendor_id' => $fixture['vendor_id'],
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $fixture['goods_receipt_id'],
            'invoice_number' => $payload['invoice_number'],
            'currency_code' => 'IDR',
            'status' => 'REGISTERED',
            'created_by' => $this->actor->id,
        ]);
        $this->assertSame($payload['invoice_number'], $invoice->invoice_number);
        $this->assertEquals('2026-06-30', $invoice->invoice_date->toDateString());
        $this->assertEquals('125.00', $invoice->grand_total);
        $this->assertCount(1, $invoice->lines);

        $line = $invoice->lines->first();
        $this->assertSame($fixture['purchase_order_line_id'], $line->purchase_order_line_id);
        $this->assertSame($fixture['goods_receipt_line_id'], $line->goods_receipt_line_id);
        $this->assertSame($fixture['inventory_item_id'], $line->inventory_item_id);
        $this->assertEquals('10.000', $line->quantity);
        $this->assertEquals('12.50', $line->unit_price);
        $this->assertEquals('125.00', $line->line_total);

        $this->assertSame(MatchStatusEnum::Matched, $match->status);
        $this->assertNull($match->exception_code);
        $this->assertCount(1, $match->lines);
        $this->assertEquals('0.0000', $match->total_quantity_variance);
        $this->assertEquals('0.00', $match->total_price_variance);
        $this->assertEquals('0.00', $match->total_amount_variance);

        $this->assertControlledSnapshotUnchanged($controlledBefore);
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
    }

    public function test_registration_failure_leaves_no_partial_header_line_or_match_evidence(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [], [
            'purchase_order_line_id' => (string) Str::ulid(),
        ]);
        $before = $this->invoiceEvidenceCounts();

        try {
            $this->service->registerAndMatch($payload, $this->actor);
            $this->fail('Registration with unresolved line provenance must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('purchase order line', $exception->getMessage());
        }

        $this->assertSame($before, $this->invoiceEvidenceCounts());
        $this->assertDatabaseMissing('vendor_invoices', [
            'invoice_number' => $payload['invoice_number'],
        ]);
    }

    public function test_quantity_variance_creates_exception_evidence_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [], [
            'quantity' => 8,
            'unit_price' => 12.50,
        ]);
        $before = $this->controlledSnapshot();

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::QuantityVariance, $match->exception_code);
        $this->assertEquals('-2.0000', $match->total_quantity_variance);
        $this->assertEquals('-25.00', $match->total_amount_variance);
        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_price_variance_creates_exception_evidence_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [], [
            'quantity' => 10,
            'unit_price' => 13.00,
        ]);
        $before = $this->controlledSnapshot();

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::PriceVariance, $match->exception_code);
        $this->assertEquals('0.50', $match->total_price_variance);
        $this->assertEquals('5.00', $match->total_amount_variance);
        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_vendor_mismatch_creates_controlled_exception_without_source_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $invoiceVendorId = $this->makeVendor($this->property, 'ALT-' . $this->sequence++);
        $payload = $this->invoicePayload($fixture, [
            'vendor_id' => $invoiceVendorId,
        ]);
        $before = $this->controlledSnapshot();
        $sourceBefore = $this->sourceSnapshot($fixture);

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::VendorMismatch, $match->exception_code);
        $this->assertCount(0, $match->lines);
        $this->assertControlledSnapshotUnchanged($before);
        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
    }

    public function test_missing_receiving_creates_exception_evidence_without_accounting_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture, [
            'goods_receipt_id' => null,
        ], [
            'goods_receipt_line_id' => null,
        ]);
        $before = $this->controlledSnapshot();

        $match = $this->service->registerAndMatch($payload, $this->actor)['match'];

        $this->assertSame(MatchStatusEnum::Exception, $match->status);
        $this->assertSame(MatchExceptionEnum::MissingGoodsReceipt, $match->exception_code);
        $this->assertCount(1, $match->lines);
        $this->assertNull($match->lines->first()->goods_receipt_line_id);
        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_duplicate_supplier_invoice_fails_without_duplicate_invoice_or_match_evidence(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $payload = $this->invoicePayload($fixture);

        $this->service->registerAndMatch($payload, $this->actor);
        $before = $this->invoiceEvidenceCounts();

        try {
            $this->service->registerAndMatch($payload, $this->actor);
            $this->fail('Duplicate supplier invoice registration must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertSame($before, $this->invoiceEvidenceCounts());
        $this->assertSame(1, DB::table('vendor_invoices')->where('invoice_number', $payload['invoice_number'])->count());
        $this->assertSame(1, DB::table('three_way_matches')->count());
        $this->assertSame(1, DB::table('three_way_match_lines')->count());
    }

    public function test_reevaluating_same_persisted_invoice_is_idempotent(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $result = $this->service->registerAndMatch($this->invoicePayload($fixture), $this->actor);
        $before = $this->invoiceEvidenceCounts();

        $secondMatch = app(ThreeWayMatchingEngine::class)->performMatch($result['invoice']->fresh(['lines']));

        $this->assertSame($result['match']->id, $secondMatch->id);
        $this->assertSame($before, $this->invoiceEvidenceCounts());
    }

    public function test_unauthorized_unresolved_and_disabled_actors_fail_closed(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $disabled = $this->makeUser(false);
        $this->attachActorToProperty($disabled, $this->property);
        $disabled->givePermissionTo(SupplierInvoiceRegistrationService::PERMISSION);

        foreach ([$unauthorized, $disabled] as $actor) {
            $payload = $this->invoicePayload($fixture);
            $before = $this->invoiceEvidenceCounts();

            try {
                $this->service->registerAndMatch($payload, $actor);
                $this->fail('Registration must fail closed for unauthorized or disabled actors.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $this->invoiceEvidenceCounts());
                $this->assertDatabaseMissing('vendor_invoices', [
                    'invoice_number' => $payload['invoice_number'],
                ]);
            }
        }
    }

    public function test_cross_property_vendor_po_and_receiving_relations_fail_closed_without_source_mutation(): void
    {
        $fixture = $this->makePurchasingFixture($this->property);
        $otherProperty = $this->makeProperty();
        $otherFixture = $this->makePurchasingFixture($otherProperty);
        $sourceBefore = $this->sourceSnapshot($fixture);

        $cases = [
            $this->invoicePayload($fixture, ['vendor_id' => $otherFixture['vendor_id']]),
            $this->invoicePayload($fixture, ['purchase_order_id' => $otherFixture['purchase_order_id']]),
            $this->invoicePayload($fixture, ['goods_receipt_id' => $otherFixture['goods_receipt_id']], [
                'goods_receipt_line_id' => $otherFixture['goods_receipt_line_id'],
            ]),
        ];

        foreach ($cases as $payload) {
            $before = $this->invoiceEvidenceCounts();

            try {
                $this->service->registerAndMatch($payload, $this->actor);
                $this->fail('Cross-property supplier invoice evidence must fail closed.');
            } catch (DomainException) {
                $this->assertSame($before, $this->invoiceEvidenceCounts());
                $this->assertDatabaseMissing('vendor_invoices', [
                    'invoice_number' => $payload['invoice_number'],
                ]);
            }
        }

        $this->assertSame($sourceBefore, $this->sourceSnapshot($fixture));
    }

    private function attachActorToProperty(User $actor, Property $property): void
    {
        $actor->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'Supplier Invoice Company ' . $suffix,
            'slug' => 'supplier-invoice-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Supplier Invoice Property ' . $suffix,
            'slug' => 'supplier-invoice-property-' . $suffix,
            'code' => 'SIP' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(bool $active = true): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'Supplier Invoice User ' . $suffix,
            'email' => 'supplier-invoice-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => $active,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function makePurchasingFixture(Property $property): array
    {
        $vendorId = $this->makeVendor($property, 'SUP-' . $this->sequence++);
        $departmentId = (string) Str::ulid();
        $requestId = (string) Str::ulid();
        $purchaseOrderId = (string) Str::ulid();
        $unitId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $purchaseOrderLineId = (string) Str::ulid();
        $goodsReceiptId = (string) Str::ulid();
        $goodsReceiptLineId = (string) Str::ulid();
        $timestamp = now();

        DB::table('departments')->insert([
            'id' => $departmentId,
            'property_id' => $property->id,
            'name' => 'Purchasing ' . $this->sequence,
            'code' => 'PUR-' . $this->sequence++,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('purchase_requests')->insert([
            'id' => $requestId,
            'property_id' => $property->id,
            'request_no' => 'PR-' . $this->sequence++,
            'department_id' => $departmentId,
            'requester_id' => $this->actor->id,
            'required_date' => '2026-07-05',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'estimated_total' => 125,
            'status' => 'APPROVED',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('purchase_orders')->insert([
            'id' => $purchaseOrderId,
            'property_id' => $property->id,
            'po_no' => 'PO-' . $this->sequence++,
            'vendor_id' => $vendorId,
            'purchase_request_id' => $requestId,
            'issue_date' => '2026-06-29',
            'expected_delivery_date' => '2026-07-05',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'subtotal' => 125,
            'tax_amount' => 0,
            'total_amount' => 125,
            'received_total' => 125,
            'status' => 'ISSUED',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_categories')->insert([
            'id' => $categoryId,
            'property_id' => $property->id,
            'name' => 'Food ' . $this->sequence++,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_units')->insert([
            'id' => $unitId,
            'property_id' => $property->id,
            'code' => 'EA-' . $this->sequence++,
            'name' => 'Each',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('inventory_items')->insert([
            'id' => $itemId,
            'property_id' => $property->id,
            'sku' => 'SKU-' . $this->sequence++,
            'name' => 'Supplier invoice test item',
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
            'property_id' => $property->id,
            'name' => 'Main Store ' . $this->sequence++,
            'type' => 'storeroom',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('purchase_order_lines')->insert([
            'id' => $purchaseOrderLineId,
            'purchase_order_id' => $purchaseOrderId,
            'inventory_item_id' => $itemId,
            'description' => 'Supplier invoice test item',
            'ordered_quantity' => 10,
            'received_quantity' => 10,
            'invoiced_quantity' => 0,
            'receiving_tolerance_percent' => 0,
            'unit_id' => $unitId,
            'unit_cost' => 12.50,
            'line_total' => 125,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('receiving_documents')->insert([
            'id' => $goodsReceiptId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'purchase_order_id' => $purchaseOrderId,
            'grn_number' => 'GRN-' . $this->sequence++,
            'status' => 'approved',
            'received_at' => '2026-06-30 00:00:00',
            'received_by' => $this->actor->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('receiving_lines')->insert([
            'id' => $goodsReceiptLineId,
            'receiving_document_id' => $goodsReceiptId,
            'purchase_order_line_id' => $purchaseOrderLineId,
            'inventory_item_id' => $itemId,
            'inventory_unit_id' => $unitId,
            'destination_location_id' => $locationId,
            'description' => 'Supplier invoice test item',
            'received_quantity' => 10,
            'unit_cost' => 12.50,
            'line_total' => 125,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_line_id' => $purchaseOrderLineId,
            'goods_receipt_id' => $goodsReceiptId,
            'goods_receipt_line_id' => $goodsReceiptLineId,
            'inventory_item_id' => $itemId,
            'currency_code' => 'IDR',
        ];
    }

    private function makeVendor(Property $property, string $code): string
    {
        $categoryId = (string) Str::ulid();
        $vendorId = (string) Str::ulid();
        $timestamp = now();

        DB::table('vendor_categories')->insert([
            'id' => $categoryId,
            'property_id' => $property->id,
            'category_code' => 'VC-' . $code,
            'name' => 'Vendor Category ' . $code,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'vendor_category_id' => $categoryId,
            'vendor_code' => $code,
            'name' => 'Vendor ' . $code,
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'is_approved' => true,
            'performance_score' => 0,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $vendorId;
    }

    private function invoicePayload(array $fixture, array $headerOverrides = [], array $lineOverrides = []): array
    {
        $quantity = (float) ($lineOverrides['quantity'] ?? 10);
        $unitPrice = (float) ($lineOverrides['unit_price'] ?? 12.50);
        $lineTotal = array_key_exists('line_total', $lineOverrides)
            ? (float) $lineOverrides['line_total']
            : round($quantity * $unitPrice, 2);

        $payload = [
            'property_id' => $fixture['property_id'],
            'vendor_id' => $fixture['vendor_id'],
            'purchase_order_id' => $fixture['purchase_order_id'],
            'goods_receipt_id' => $fixture['goods_receipt_id'],
            'invoice_number' => 'SINV-' . $this->sequence++,
            'invoice_date' => '2026-06-30',
            'currency_code' => $fixture['currency_code'],
            'tax_amount' => 0,
            'discount_amount' => 0,
            'remarks' => 'Supplier invoice registration test',
            'lines' => [[
                'purchase_order_line_id' => $fixture['purchase_order_line_id'],
                'goods_receipt_line_id' => $fixture['goods_receipt_line_id'],
                'inventory_item_id' => $fixture['inventory_item_id'],
                'description' => 'Supplier invoice test item',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]],
        ];

        $payload = array_replace($payload, $headerOverrides);
        $payload['lines'][0] = array_replace($payload['lines'][0], $lineOverrides);

        return $payload;
    }

    private function invoiceEvidenceCounts(): array
    {
        return [
            'vendor_invoices' => DB::table('vendor_invoices')->count(),
            'vendor_invoice_lines' => DB::table('vendor_invoice_lines')->count(),
            'three_way_matches' => DB::table('three_way_matches')->count(),
            'three_way_match_lines' => DB::table('three_way_match_lines')->count(),
        ];
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'accounts_payables',
            'payment_vouchers',
            'payment_voucher_lines',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'financial_periods',
            'gl_financial_periods',
            'property_business_dates',
            'inventory_transactions',
            'cost_ledger_entries',
            'cost_avco_states',
            'inventory_receipts',
            'inventory_receipt_lines',
            'receiving_documents',
            'receiving_lines',
        ];

        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchanged(array $before): void
    {
        $this->assertSame($before, $this->controlledSnapshot());
    }

    private function sourceSnapshot(array $fixture): array
    {
        return [
            'purchase_order' => (array) DB::table('purchase_orders')
                ->where('id', $fixture['purchase_order_id'])
                ->first(['vendor_id', 'currency_code', 'total_amount', 'received_total', 'status']),
            'purchase_order_line' => (array) DB::table('purchase_order_lines')
                ->where('id', $fixture['purchase_order_line_id'])
                ->first(['ordered_quantity', 'received_quantity', 'invoiced_quantity', 'unit_cost', 'line_total']),
            'goods_receipt' => (array) DB::table('receiving_documents')
                ->where('id', $fixture['goods_receipt_id'])
                ->first(['purchase_order_id', 'vendor_id', 'status']),
            'goods_receipt_line' => (array) DB::table('receiving_lines')
                ->where('id', $fixture['goods_receipt_line_id'])
                ->first(['purchase_order_line_id', 'received_quantity', 'unit_cost', 'line_total']),
        ];
    }
}
