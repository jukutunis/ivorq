<?php

namespace Tests\Postgres\Operations\Purchasing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Repositories\VendorRepository;
use Modules\Operations\Purchasing\Services\PurchaseOrderService;
use Modules\Operations\Purchasing\Services\PurchaseRequestService;
use Modules\Operations\Purchasing\Services\VendorService;
use Shared\Exceptions\BusinessLogicException;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class ProcurementControlPlaneTest extends TestCase
{
    use CreatesFoundationData;
    use RefreshDatabase;

    private Property $property;

    private User $maker;

    private User $checker;

    private InventoryItem $item;

    private InventoryUnit $unit;

    private VendorCategory $vendorCategory;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        $company = $this->createCompany();
        $this->property = $this->createProperty($company, ['currency' => 'IDR']);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->maker = $this->user('maker@example.test');
        $this->checker = $this->user('checker@example.test', true);

        $inventoryCategory = InventoryCategory::create([
            'property_id' => $this->property->id,
            'name' => 'Operating supplies',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $inventoryCategory->id,
            'sku' => 'OS-001',
            'name' => 'Operating Supply',
            'inventory_type' => 'goods',
            'is_active' => true,
        ]);
        $this->unit = InventoryUnit::create([
            'property_id' => $this->property->id,
            'code' => 'EA',
            'name' => 'Each',
        ]);
        $this->vendorCategory = VendorCategory::create([
            'property_id' => $this->property->id,
            'category_code' => 'OPS',
            'name' => 'Operations',
        ]);
    }

    public function test_company_vendor_is_visible_across_same_company_properties_but_not_another_company_and_approval_is_not_a_toggle(): void
    {
        $vendor = Vendor::create([
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $this->vendorCategory->id,
            'vendor_code' => 'TENANT-01',
            'name' => 'Tenant Vendor',
            'is_active' => true,
            'is_approved' => false,
        ]);

        $sameCompanyProperty = $this->createProperty($this->property->company, ['currency' => 'IDR']);
        app(CurrentPropertyService::class)->setPropertyId($sameCompanyProperty->id);
        $this->assertSame($vendor->id, app(VendorRepository::class)->findOrFail($vendor->id)->id);

        $otherProperty = $this->createProperty($this->createCompany(), ['currency' => 'IDR']);
        app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
        $this->expectException(ModelNotFoundException::class);
        app(VendorRepository::class)->findOrFail($vendor->id);
    }

    public function test_vendor_approval_replay_remains_approved(): void
    {
        $vendor = $this->vendor(false);
        $service = app(VendorService::class);

        $this->assertTrue($service->approve($vendor->id)->is_approved);
        $this->assertTrue($service->approve($vendor->id)->is_approved);
    }

    public function test_workspace_denies_unauthorized_actor(): void
    {
        $unauthorized = $this->user('unauthorized@example.test');
        $this->actingAs($unauthorized)
            ->get('/operations/procurement')
            ->assertForbidden();
    }

    public function test_workspace_projection_is_tenant_scoped(): void
    {
        $visible = $this->vendor(true);
        $otherProperty = $this->createProperty($this->createCompany(), ['currency' => 'IDR']);
        $otherCategory = VendorCategory::withoutGlobalScope('property')->create([
            'property_id' => $otherProperty->id,
            'category_code' => 'OTHER',
            'name' => 'Other tenant',
        ]);
        $hidden = Vendor::create([
            'company_id' => $otherProperty->company_id,
            'vendor_category_id' => $otherCategory->id,
            'vendor_code' => 'OTHER-1',
            'name' => 'Other Tenant Vendor',
            'is_active' => true,
            'is_approved' => true,
        ]);

        $this->actingAs($this->checker)
            ->withSession([
                'current_property_id' => $this->property->id,
                'active_property_id' => $this->property->id,
            ])
            ->get('/operations/procurement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operations/Purchasing/ProcurementWorkspace')
                ->where('context.property_id', $this->property->id)
                ->has('vendors', 1)
                ->where('vendors.0.id', $visible->id)
                ->missing('vendors.1')
            );

        $this->assertNotSame($visible->id, $hidden->id);
    }

    public function test_pr_is_server_owned_replay_safe_and_maker_checker_controlled(): void
    {
        $department = $this->createDepartment($this->property);
        $this->workflow(PurchaseRequest::class);
        $service = app(PurchaseRequestService::class);
        $payload = [
            'department_id' => $department->id,
            'required_date' => now()->addDays(5)->toDateString(),
            'remarks' => 'Housekeeping operating stock',
            'idempotency_key' => 'pr-replay-1',
        ];
        $lines = [[
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'quantity' => '2',
            'estimated_unit_cost' => '0',
        ]];

        $request = $service->createWithLines($payload, $lines, $this->maker);
        $replay = $service->createWithLines($payload, $lines, $this->maker);

        $this->assertSame($request->id, $replay->id);
        $this->assertSame($this->property->id, $request->property_id);
        $this->assertSame($this->maker->id, $request->requester_id);
        $this->assertMatchesRegularExpression('/^PR-\d{4}-\d{6}$/', $request->request_no);
        $this->assertSame('IDR', $request->currency_code);
        $this->assertSame('Operating Supply', $request->lines->first()->description);

        $changed = $payload;
        $changed['remarks'] = 'Different request';
        try {
            $service->createWithLines($changed, $lines, $this->maker);
            $this->fail('Conflicting replay must fail closed.');
        } catch (BusinessLogicException $exception) {
            $this->assertStringContainsString('different content', $exception->getMessage());
        }

        $submitted = $service->submit($request->id, $this->maker);
        $this->assertSame(PurchaseRequestStatusEnum::PendingReview, $submitted->status);

        try {
            $service->approve($request->id, $this->maker);
            $this->fail('Maker approval must fail closed.');
        } catch (BusinessLogicException $exception) {
            $this->assertStringContainsString('requester cannot', $exception->getMessage());
        }

        $approved = $service->approve($request->id, $this->checker);
        $this->assertSame(PurchaseRequestStatusEnum::Approved, $approved->status);
        $this->assertSame($this->checker->id, $approved->approved_by);
    }

    public function test_po_requires_approved_pr_and_company_vendor_then_uses_controlled_approval_and_idempotent_issue(): void
    {
        $department = $this->createDepartment($this->property);
        $request = app(PurchaseRequestService::class)->createWithLines([
            'department_id' => $department->id,
            'required_date' => now()->addDays(5)->toDateString(),
        ], [[
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'quantity' => '2',
            'estimated_unit_cost' => '10',
        ]], $this->maker);
        $vendor = $this->vendor(true);
        $service = app(PurchaseOrderService::class);

        try {
            $service->createFromApprovedPR($request->id, $vendor->id, ['expected_delivery_date' => now()->addDays(7)], $this->maker);
            $this->fail('Draft PR conversion must fail closed.');
        } catch (BusinessLogicException $exception) {
            $this->assertStringContainsString('Approved Purchase Request', $exception->getMessage());
        }

        $request->update(['status' => PurchaseRequestStatusEnum::Approved]);
        $order = $service->createFromApprovedPR($request->id, $vendor->id, [
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
        ], $this->maker);
        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{6}$/', $order->po_no);
        $this->assertSame(PurchaseOrderStatusEnum::Draft, $order->status);
        $this->assertDatabaseHas('purchase_order_receipt_source_authorities', [
            'property_id' => $this->property->id,
            'purchase_order_id' => $order->id,
            'source_type' => 'inventory_goods_receipt',
            'binding_reason' => 'controlled_default_new_purchase_order',
        ]);

        try {
            $service->issue($order->id, $this->checker, 'issue-1');
            $this->fail('Draft PO issue must fail closed.');
        } catch (BusinessLogicException $exception) {
            $this->assertStringContainsString('Approved Purchase Order', $exception->getMessage());
        }

        $this->workflow(PurchaseOrder::class);
        $service->submit($order->id, $this->maker);
        try {
            $service->approve($order->id, $this->maker);
            $this->fail('PO maker approval must fail closed.');
        } catch (BusinessLogicException $exception) {
            $this->assertStringContainsString('creator cannot', $exception->getMessage());
        }

        $approved = $service->approve($order->id, $this->checker);
        $this->assertSame(PurchaseOrderStatusEnum::Approved, $approved->status);

        $this->actingAs($this->checker)
            ->withSession([
                'current_property_id' => $this->property->id,
                'active_property_id' => $this->property->id,
            ])
            ->get('/operations/procurement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders.0.id', $order->id)
                ->where(
                    'purchaseOrders.0.receiving_url',
                    route('operations.inventory.goods-receipts.index', ['purchase_order_id' => $order->id])
                )
            );

        DB::table('purchase_order_receipt_source_authorities')
            ->where('purchase_order_id', $order->id)
            ->update(['source_type' => 'legacy_receiving_document']);

        $this->actingAs($this->checker)
            ->withSession([
                'current_property_id' => $this->property->id,
                'active_property_id' => $this->property->id,
            ])
            ->get('/operations/procurement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders.0.id', $order->id)
                ->where('purchaseOrders.0.receiving_url', null)
            );

        DB::table('purchase_order_receipt_source_authorities')
            ->where('purchase_order_id', $order->id)
            ->update(['source_type' => 'inventory_goods_receipt']);

        $issued = $service->issue($order->id, $this->checker, 'issue-1');
        $taskCount = Task::query()->where('taskable_id', $order->id)->where('title', 'like', 'PO Issued:%')->count();
        $notificationCount = AppNotification::query()->where('type', 'purchasing.po_issued')->where('body', 'like', "%{$order->po_no}%")->count();
        $replay = $service->issue($order->id, $this->checker, 'issue-1');

        $this->assertSame(PurchaseOrderStatusEnum::Issued, $issued->status);
        $this->assertSame($issued->id, $replay->id);
        $this->assertSame($taskCount, Task::query()->where('taskable_id', $order->id)->where('title', 'like', 'PO Issued:%')->count());
        $this->assertSame($notificationCount, AppNotification::query()->where('type', 'purchasing.po_issued')->where('body', 'like', "%{$order->po_no}%")->count());
    }

    private function user(string $email, bool $systemAdmin = false): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'is_system_admin' => $systemAdmin,
        ]);
        $user->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function workflow(string $type): void
    {
        $workflow = ApprovalWorkflow::create([
            'property_id' => $this->property->id,
            'approvable_type' => $type,
            'name' => class_basename($type).' approval',
            'is_active' => true,
        ]);
        ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Independent checker',
            'required_approvals' => 1,
        ]);
    }

    private function vendor(bool $approved): Vendor
    {
        return Vendor::create([
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $this->vendorCategory->id,
            'vendor_code' => 'V-'.($approved ? 'APPROVED' : 'PENDING'),
            'name' => 'Controlled Vendor',
            'is_active' => true,
            'is_approved' => $approved,
        ]);
    }
}
