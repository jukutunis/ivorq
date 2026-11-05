<?php

namespace Tests\Postgres\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderRevisionSnapshot;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\PurchaseRequestRevisionSnapshot;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Services\PurchaseOrderService;
use Modules\Operations\Purchasing\Services\PurchaseRequestService;
use Shared\Exceptions\BusinessLogicException;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class ProcurementRejectedRevisionLifecycleTest extends TestCase
{
    use CreatesFoundationData;
    use RefreshDatabase;

    private Property $property;

    private User $maker;

    private User $checker;

    private InventoryItem $item;

    private InventoryUnit $unit;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->property = $this->createProperty($this->createCompany(), ['currency' => 'IDR']);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->maker = $this->user('revision-maker@example.test');
        $this->checker = $this->user('revision-checker@example.test');

        $category = InventoryCategory::create([
            'property_id' => $this->property->id,
            'name' => 'Revision supplies',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'REV-001',
            'name' => 'Revision Supply',
            'inventory_type' => 'goods',
            'is_active' => true,
        ]);
        $this->unit = InventoryUnit::create([
            'property_id' => $this->property->id,
            'code' => 'EA',
            'name' => 'Each',
        ]);
        $vendorCategory = VendorCategory::create([
            'property_id' => $this->property->id,
            'category_code' => 'REV',
            'name' => 'Revision vendors',
        ]);
        $this->vendor = Vendor::create([
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $vendorCategory->id,
            'vendor_code' => 'REV-VENDOR',
            'name' => 'Revision Vendor',
            'is_active' => true,
            'is_approved' => true,
        ]);

        $this->workflow(PurchaseRequest::class);
        $this->workflow(PurchaseOrder::class);
    }

    public function test_rejected_purchase_request_revises_same_aggregate_with_immutable_prior_evidence_and_new_approval(): void
    {
        $service = app(PurchaseRequestService::class);
        $request = $this->draftRequest('pr-revision-proof');
        $originalLineId = $request->lines->first()->id;

        $service->submit($request->id, $this->maker);
        $service->reject($request->id, $this->checker, 'Quantity needs correction.');

        $this->expectBusinessFailure(
            fn () => $service->updateWithLines($request->id, ['remarks' => 'Direct mutation'], null, $this->maker),
            'Only a Draft Purchase Request may be edited.'
        );

        $revised = $service->revise($request->id, $this->maker);
        $snapshot = PurchaseRequestRevisionSnapshot::query()->sole();

        $this->assertSame($request->id, $revised->id);
        $this->assertSame(2, $revised->revision_number);
        $this->assertSame($this->maker->id, $revised->current_revision_created_by);
        $this->assertSame(PurchaseRequestStatusEnum::Draft, $revised->status);
        $this->assertNull($revised->rejected_by);
        $this->assertSame($originalLineId, $revised->lines->first()->id);
        $this->assertSame(1, $snapshot->revision_number);
        $this->assertSame('Quantity needs correction.', $snapshot->rejection_reason);
        $this->assertSame($originalLineId, $snapshot->commercial_lines[0]['id']);
        $this->assertSame('2.000', $snapshot->commercial_lines[0]['quantity']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot->source_fingerprint);

        try {
            $snapshot->update(['rejection_reason' => 'mutated']);
            $this->fail('Revision snapshot mutation must fail closed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $service->updateWithLines($request->id, ['remarks' => 'Corrected revision'], [[
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'quantity' => '3',
            'estimated_unit_cost' => '0',
        ]], $this->maker);
        $service->submit($request->id, $this->maker);

        $this->assertSame(2, ApprovalRequest::query()
            ->where('approvable_type', PurchaseRequest::class)
            ->where('approvable_id', $request->id)
            ->count());
        $this->expectBusinessFailure(
            fn () => $service->approve($request->id, $this->maker),
            'requester cannot'
        );
        $approved = $service->approve($request->id, $this->checker);
        $this->assertSame(PurchaseRequestStatusEnum::Approved, $approved->status);
        $this->assertSame(3.0, (float) $approved->lines->first()->quantity);
    }

    public function test_rejected_purchase_order_revises_same_po_and_pr_lineage_then_issues_after_independent_approval(): void
    {
        $request = $this->draftRequest('po-revision-source');
        $request->update(['status' => PurchaseRequestStatusEnum::Approved]);
        $service = app(PurchaseOrderService::class);
        $order = $service->createFromApprovedPR($request->id, $this->vendor->id, [
            'expected_delivery_date' => now()->addWeek()->toDateString(),
        ], $this->maker);
        $originalLineId = $order->lines->first()->id;

        $service->submit($order->id, $this->maker);
        $service->reject($order->id, $this->checker, 'Commercial terms need correction.');

        $this->expectBusinessFailure(
            fn () => $service->update($order->id, ['remarks' => 'Direct mutation'], $this->maker),
            'Only a Draft Purchase Order may be edited.'
        );

        $revised = $service->revise($order->id, $this->maker);
        $snapshot = PurchaseOrderRevisionSnapshot::query()->sole();

        $this->assertSame($order->id, $revised->id);
        $this->assertSame($request->id, $revised->purchase_request_id);
        $this->assertSame(2, $revised->revision_number);
        $this->assertSame(PurchaseOrderStatusEnum::Draft, $revised->status);
        $this->assertSame($originalLineId, $revised->lines->first()->id);
        $this->assertSame(1, PurchaseOrder::query()->where('purchase_request_id', $request->id)->count());
        $this->assertSame($originalLineId, $snapshot->commercial_lines[0]['id']);
        $this->assertSame('Commercial terms need correction.', $snapshot->rejection_reason);

        $service->update($order->id, [
            'expected_delivery_date' => now()->addDays(10)->toDateString(),
            'remarks' => 'Corrected PO revision',
        ], $this->maker, [[
            'id' => $originalLineId,
            'ordered_quantity' => '2',
            'unit_cost' => '12',
        ]]);
        $service->submit($order->id, $this->maker);

        $this->assertSame(2, ApprovalRequest::query()
            ->where('approvable_type', PurchaseOrder::class)
            ->where('approvable_id', $order->id)
            ->count());
        $this->expectBusinessFailure(
            fn () => $service->approve($order->id, $this->maker),
            'creator cannot'
        );
        $service->approve($order->id, $this->checker);
        $issued = $service->issue($order->id, $this->checker, 'revision-issue-proof');

        $this->assertSame(PurchaseOrderStatusEnum::Issued, $issued->status);
        $this->assertSame(24.0, (float) $issued->total_amount);
        $this->assertSame(1, PurchaseOrder::query()->where('purchase_request_id', $request->id)->count());
    }

    public function test_purchase_request_draft_edit_rebinds_current_revision_maker_before_approval(): void
    {
        $editor = $this->user('pr-current-maker-editor@example.test');
        $service = app(PurchaseRequestService::class);
        $request = $this->draftRequest('pr-current-maker-proof');

        $edited = $service->updateWithLines($request->id, [
            'remarks' => 'Commercial draft edited by delegated maker.',
        ], [[
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'quantity' => '4',
            'estimated_unit_cost' => '0',
        ]], $editor);

        $this->assertSame($this->maker->id, $edited->requester_id);
        $this->assertSame($this->maker->id, $edited->created_by);
        $this->assertSame($editor->id, $edited->current_revision_created_by);
        $this->assertSame($editor->id, $edited->updated_by);

        $service->submit($request->id, $editor);
        $this->expectBusinessFailure(
            fn () => $service->approve($request->id, $editor),
            'requester cannot'
        );

        $approved = $service->approve($request->id, $this->checker);
        $this->assertSame(PurchaseRequestStatusEnum::Approved, $approved->status);
        $this->assertSame($this->checker->id, $approved->approved_by);
    }

    public function test_purchase_order_draft_edit_rebinds_current_revision_maker_before_approval(): void
    {
        $editor = $this->user('po-current-maker-editor@example.test');
        $request = $this->draftRequest('po-current-maker-source');
        $request->update(['status' => PurchaseRequestStatusEnum::Approved]);
        $service = app(PurchaseOrderService::class);
        $order = $service->createFromApprovedPR($request->id, $this->vendor->id, [
            'expected_delivery_date' => now()->addWeek()->toDateString(),
        ], $this->maker);
        $lineId = $order->lines->first()->id;

        $edited = $service->update($order->id, [
            'remarks' => 'Commercial PO edited by delegated maker.',
        ], $editor, [[
            'id' => $lineId,
            'ordered_quantity' => '3',
            'unit_cost' => '7',
        ]]);

        $this->assertSame($this->maker->id, $edited->created_by);
        $this->assertSame($editor->id, $edited->current_revision_created_by);
        $this->assertSame($editor->id, $edited->updated_by);
        $this->assertSame(21.0, (float) $edited->total_amount);

        $service->submit($order->id, $editor);
        $this->expectBusinessFailure(
            fn () => $service->approve($order->id, $editor),
            'creator cannot'
        );

        $approved = $service->approve($order->id, $this->checker);
        $this->assertSame(PurchaseOrderStatusEnum::Approved, $approved->status);
        $this->assertSame($this->checker->id, $approved->approved_by);
    }

    private function draftRequest(string $idempotencyKey): PurchaseRequest
    {
        $department = $this->createDepartment($this->property);

        return app(PurchaseRequestService::class)->createWithLines([
            'department_id' => $department->id,
            'required_date' => now()->addDays(5)->toDateString(),
            'idempotency_key' => $idempotencyKey,
        ], [[
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'quantity' => '2',
            'estimated_unit_cost' => '0',
        ]], $this->maker);
    }

    private function user(string $email): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'is_system_admin' => true,
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
            'name' => class_basename($type).' revision approval',
            'is_active' => true,
        ]);
        ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Independent checker',
            'required_approvals' => 1,
        ]);
    }

    private function expectBusinessFailure(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected the business rule to fail closed.');
        } catch (BusinessLogicException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
