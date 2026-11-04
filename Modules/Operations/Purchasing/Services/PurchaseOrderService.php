<?php

namespace Modules\Operations\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Approval\Models\ApprovalAction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Enums\TaskStatusEnum;
use Modules\Foundation\Task\Services\TaskService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Enums\PriorityEnum;
use Shared\Exceptions\BusinessLogicException;
use Shared\Services\CurrentPropertyService;

class PurchaseOrderService
{
    public function __construct(
        private TaskService $taskService,
        private ApprovalEngineService $approvalEngine,
        private PurchasingDocumentNumberAllocator $numberAllocator,
        private CurrentPropertyService $currentProperty,
    ) {}

    public function createFromApprovedPR(string $prId, string $vendorId, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($prId, $vendorId, $data, $actor): PurchaseOrder {
            $property = $this->activeProperty();
            $request = PurchaseRequest::query()->withoutGlobalScope('property')
                ->with('lines')
                ->whereKey($prId)
                ->where('property_id', $property->id)
                ->lockForUpdate()
                ->first();

            if (! $request || $request->status !== PurchaseRequestStatusEnum::Approved) {
                throw new BusinessLogicException('Purchase Order conversion requires an Approved Purchase Request in the active Property.');
            }

            if (PurchaseOrder::query()->withoutGlobalScope('property')->where('purchase_request_id', $request->id)->exists()) {
                throw new BusinessLogicException('A Purchase Order already exists for this Purchase Request.');
            }

            $vendor = Vendor::query()
                ->whereKey($vendorId)
                ->where('company_id', $property->company_id)
                ->where('is_active', true)
                ->where('is_approved', true)
                ->lockForUpdate()
                ->first();

            if (! $vendor) {
                throw new BusinessLogicException('Selected Vendor is not active and approved in the active Company.');
            }

            if (strtoupper((string) $request->currency_code) !== strtoupper((string) $property->currency)
                || round((float) $request->exchange_rate, 4) !== 1.0) {
                throw new BusinessLogicException('Unsupported Purchase Request currency or exchange-rate condition.');
            }

            if ($request->lines->isEmpty()) {
                throw new BusinessLogicException('Approved Purchase Request has no commercial lines.');
            }

            foreach ($request->lines as $line) {
                $itemMatches = DB::table('inventory_items')
                    ->where('id', $line->inventory_item_id)
                    ->where('property_id', $property->id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->exists();
                $unitMatches = DB::table('inventory_units')
                    ->where('id', $line->unit_id)
                    ->where('property_id', $property->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $itemMatches || ! $unitMatches || (float) $line->quantity <= 0) {
                    throw new BusinessLogicException('Approved Purchase Request contains invalid Item, UOM, or quantity evidence.');
                }
            }

            $order = PurchaseOrder::create([
                'property_id' => $property->id,
                'po_no' => $this->numberAllocator->allocate($property->id, 'PO', now()->year),
                'vendor_id' => $vendor->id,
                'purchase_request_id' => $request->id,
                'issue_date' => null,
                'expected_delivery_date' => $data['expected_delivery_date'],
                'currency_code' => $request->currency_code,
                'exchange_rate' => $request->exchange_rate,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'received_total' => 0,
                'status' => PurchaseOrderStatusEnum::Draft,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $subtotal = 0.0;
            foreach ($request->lines as $line) {
                $lineTotal = (float) $line->quantity * (float) $line->estimated_unit_cost;
                $subtotal += $lineTotal;
                $order->lines()->create([
                    'purchase_request_line_id' => $line->id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'description' => $line->description,
                    'ordered_quantity' => $line->quantity,
                    'received_quantity' => 0,
                    'invoiced_quantity' => 0,
                    'unit_id' => $line->unit_id,
                    'unit_cost' => $line->estimated_unit_cost,
                    'line_total' => $lineTotal,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $order->update(['subtotal' => $subtotal, 'total_amount' => $subtotal]);
            $request->update(['status' => PurchaseRequestStatusEnum::Converted, 'updated_by' => $actor->id]);

            return $order->fresh(['vendor', 'lines.inventoryItem', 'lines.unit', 'purchaseRequest']);
        });
    }

    public function update(string $id, array $data, User $actor, ?array $lines = null): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $data, $actor, $lines): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($order->status !== PurchaseOrderStatusEnum::Draft) {
                throw new BusinessLogicException('Only a Draft Purchase Order may be edited.');
            }

            if ($lines !== null) {
                foreach ($lines as $lineData) {
                    $line = $order->lines()->whereKey($lineData['id'])->lockForUpdate()->firstOrFail();
                    $quantity = (float) ($lineData['ordered_quantity'] ?? $line->ordered_quantity);
                    $unitCost = (float) ($lineData['unit_cost'] ?? $line->unit_cost);

                    if ($quantity <= 0 || $unitCost < 0) {
                        throw new BusinessLogicException('Purchase Order quantity and unit cost are invalid.');
                    }

                    $line->update([
                        'ordered_quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'line_total' => $quantity * $unitCost,
                        'updated_by' => $actor->id,
                    ]);
                }

                $subtotal = (float) $order->lines()->sum('line_total');
                $data['subtotal'] = $subtotal;
                $data['total_amount'] = $subtotal + (float) $order->tax_amount;
            }

            $data['updated_by'] = $actor->id;
            $order->update($data);

            return $order->fresh(['vendor', 'lines.inventoryItem', 'lines.unit', 'purchaseRequest']);
        });
    }

    public function submit(string $id, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($order->status !== PurchaseOrderStatusEnum::Draft) {
                throw new BusinessLogicException('Only a Draft Purchase Order may be submitted.');
            }

            $this->approvalEngine->submitForApproval($order, $order->created_by);
            $order->update(['status' => PurchaseOrderStatusEnum::PendingReview, 'updated_by' => $actor->id]);

            return $order->fresh(['vendor', 'lines.inventoryItem', 'lines.unit', 'purchaseRequest']);
        });
    }

    public function approve(string $id, User $actor, ?string $remarks = null): PurchaseOrder
    {
        return $this->decide($id, $actor, 'approve', $remarks);
    }

    public function reject(string $id, User $actor, string $reason): PurchaseOrder
    {
        if (trim($reason) === '') {
            throw new BusinessLogicException('A rejection reason is required.');
        }

        return $this->decide($id, $actor, 'reject', $reason);
    }

    public function issue(string $id, User $actor, string $idempotencyKey): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $idempotencyKey): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $idempotencyKey = trim($idempotencyKey);

            if ($idempotencyKey === '') {
                throw new BusinessLogicException('A Purchase Order issue idempotency key is required.');
            }

            if ($order->status === PurchaseOrderStatusEnum::Issued) {
                if (hash_equals((string) $order->issue_idempotency_key, $idempotencyKey)) {
                    return $order->fresh(['vendor', 'lines.inventoryItem', 'lines.unit', 'purchaseRequest']);
                }

                throw new BusinessLogicException('Purchase Order was already issued with different idempotency evidence.');
            }

            if ($order->status !== PurchaseOrderStatusEnum::Approved) {
                throw new BusinessLogicException('Only an Approved Purchase Order may be issued.');
            }

            $order->update([
                'status' => PurchaseOrderStatusEnum::Issued,
                'issue_date' => now()->toDateString(),
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'issue_idempotency_key' => $idempotencyKey,
                'updated_by' => $actor->id,
            ]);

            $this->taskService->create([
                'property_id' => $order->property_id,
                'task_type' => 'Action',
                'source_module' => 'purchasing',
                'taskable_type' => PurchaseOrder::class,
                'taskable_id' => $order->id,
                'title' => "PO Issued: {$order->po_no}",
                'description' => 'Purchase Order has been issued to the Vendor.',
                'priority' => PriorityEnum::Normal->value,
                'status' => TaskStatusEnum::Open->value,
                'due_date' => $order->expected_delivery_date,
            ]);

            AppNotification::create([
                'property_id' => $order->property_id,
                'user_id' => $order->created_by,
                'type' => 'purchasing.po_issued',
                'priority' => 'normal',
                'title' => 'PO Issued',
                'body' => "Purchase Order {$order->po_no} has been issued.",
            ]);

            return $order->fresh(['vendor', 'lines.inventoryItem', 'lines.unit', 'purchaseRequest']);
        });
    }

    public function cancel(string $id, User $actor, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $reason): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $reason = trim($reason);

            if ($reason === '') {
                throw new BusinessLogicException('A cancellation reason is required.');
            }

            if (! in_array($order->status, [
                PurchaseOrderStatusEnum::Draft,
                PurchaseOrderStatusEnum::PendingReview,
                PurchaseOrderStatusEnum::Approved,
                PurchaseOrderStatusEnum::Issued,
            ], true)) {
                throw new BusinessLogicException('Purchase Order cannot be cancelled from its current status.');
            }

            $receivingStarted = $order->lines()->where('received_quantity', '>', 0)->exists()
                || DB::table('receiving_documents')
                    ->where('purchase_order_id', $order->id)
                    ->whereNull('deleted_at')
                    ->exists();

            if ($receivingStarted) {
                throw new BusinessLogicException('Purchase Order cannot be cancelled after receiving has begun.');
            }

            if ($order->status === PurchaseOrderStatusEnum::PendingReview) {
                $this->approvalEngine->cancel($this->activeApproval($order), $actor->id, $reason);
            }

            $order->update([
                'status' => PurchaseOrderStatusEnum::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            return $order->fresh(['vendor', 'lines.inventoryItem', 'lines.unit', 'purchaseRequest']);
        });
    }

    private function decide(string $id, User $actor, string $decision, ?string $remarks): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $decision, $remarks): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($order->status !== PurchaseOrderStatusEnum::PendingReview) {
                throw new BusinessLogicException('Only a Pending Review Purchase Order may be decided.');
            }

            if ($order->created_by === $actor->id) {
                throw new BusinessLogicException('The Purchase Order creator cannot approve or reject their own order.');
            }

            if (! $actor->can('purchasing.purchase-order.approve')) {
                throw new BusinessLogicException('Purchase Order approval permission is required.');
            }

            if (! $actor->properties()
                ->where('properties.id', $order->property_id)
                ->wherePivot('status', 'active')
                ->exists()) {
                throw new BusinessLogicException('Purchase Order decision requires active Property membership.');
            }

            $approval = $this->activeApproval($order);

            if (ApprovalAction::query()
                ->where('approval_request_id', $approval->id)
                ->where('approval_step_id', $approval->current_step_id)
                ->where('user_id', $actor->id)
                ->exists()) {
                throw new BusinessLogicException('The checker has already recorded a decision for this approval step.');
            }

            if ($decision === 'approve') {
                $this->approvalEngine->approve($approval, $actor->id, $remarks);
            } else {
                $this->approvalEngine->reject($approval, $actor->id, $remarks);
            }

            return $order->fresh(['vendor', 'lines.inventoryItem', 'lines.unit', 'purchaseRequest']);
        });
    }

    private function activeApproval(PurchaseOrder $order): ApprovalRequest
    {
        $approval = ApprovalRequest::query()
            ->where('approvable_type', PurchaseOrder::class)
            ->where('approvable_id', $order->id)
            ->whereIn('status', ['Pending', 'In Progress'])
            ->latest('requested_at')
            ->first();

        if (! $approval) {
            throw new BusinessLogicException('Active Purchase Order approval evidence was not found.');
        }

        return $approval;
    }

    private function activeProperty(): Property
    {
        return Property::query()
            ->whereKey($this->currentProperty->resolveOrFail())
            ->where('is_active', true)
            ->firstOrFail();
    }
}
