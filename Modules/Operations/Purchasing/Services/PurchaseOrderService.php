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
use Modules\Operations\Purchasing\Models\PurchaseOrderRevisionSnapshot;
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
                'revision_number' => 1,
                'current_revision_created_by' => $actor->id,
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

            $this->approvalEngine->submitForApproval($order, $this->revisionMakerId($order));
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

    public function revise(string $id, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $property = $this->assertRevisionAuthority($actor, $order->property_id);

            if ($order->status !== PurchaseOrderStatusEnum::Rejected) {
                throw new BusinessLogicException('Only a Rejected Purchase Order may be revised.');
            }

            $lines = $order->lines()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $receivingStarted = $lines->contains(fn ($line): bool => (float) $line->received_quantity > 0)
                || DB::table('receiving_documents')
                    ->where('purchase_order_id', $order->id)
                    ->whereNull('deleted_at')
                    ->exists();

            if ($receivingStarted) {
                throw new BusinessLogicException('Purchase Order cannot be revised after receiving has begun.');
            }

            $approval = $this->rejectedApproval($order);
            $revisionMakerId = $this->revisionMakerId($order);

            if (! $order->rejected_by || ! $order->rejected_at || trim((string) $order->rejection_reason) === '') {
                throw new BusinessLogicException('Purchase Order rejection evidence is incomplete.');
            }

            $header = [
                'purchase_order_id' => $order->id,
                'po_no' => $order->po_no,
                'purchase_request_id' => $order->purchase_request_id,
                'vendor_id' => $order->vendor_id,
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'currency_code' => $order->currency_code,
                'exchange_rate' => (string) $order->exchange_rate,
                'subtotal' => (string) $order->subtotal,
                'tax_amount' => (string) $order->tax_amount,
                'total_amount' => (string) $order->total_amount,
                'remarks' => $order->remarks,
                'original_created_by' => $order->created_by,
                'rejected_by' => $order->rejected_by,
                'rejected_at' => $order->rejected_at->toISOString(),
                'rejection_reason' => $order->rejection_reason,
                'approval_request_id' => $approval->id,
            ];
            $commercialLines = $lines->map(fn ($line): array => $line->only([
                'id',
                'purchase_order_id',
                'purchase_request_line_id',
                'inventory_item_id',
                'description',
                'ordered_quantity',
                'received_quantity',
                'invoiced_quantity',
                'unit_id',
                'unit_cost',
                'line_total',
            ]))->all();
            $fingerprint = $this->fingerprint([
                'revision_number' => (int) $order->revision_number,
                'header' => $header,
                'lines' => $commercialLines,
            ]);

            PurchaseOrderRevisionSnapshot::create([
                'purchase_order_id' => $order->id,
                'revision_number' => $order->revision_number,
                'property_id' => $order->property_id,
                'company_id' => $property->company_id,
                'document_number' => $order->po_no,
                'decision_status' => PurchaseOrderStatusEnum::Rejected->value,
                'revision_maker_id' => $revisionMakerId,
                'rejected_by' => $order->rejected_by,
                'approval_request_id' => $approval->id,
                'rejection_reason' => $order->rejection_reason,
                'rejected_at' => $order->rejected_at,
                'commercial_header' => $header,
                'commercial_lines' => $commercialLines,
                'source_fingerprint' => $fingerprint,
            ]);

            $order->update([
                'revision_number' => ((int) $order->revision_number) + 1,
                'current_revision_created_by' => $actor->id,
                'status' => PurchaseOrderStatusEnum::Draft,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'updated_by' => $actor->id,
            ]);

            return $order->fresh([
                'vendor',
                'lines.inventoryItem',
                'lines.unit',
                'purchaseRequest',
                'revisionSnapshots',
            ]);
        });
    }

    private function decide(string $id, User $actor, string $decision, ?string $remarks): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $decision, $remarks): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($order->status !== PurchaseOrderStatusEnum::PendingReview) {
                throw new BusinessLogicException('Only a Pending Review Purchase Order may be decided.');
            }

            if ($this->revisionMakerId($order) === $actor->id) {
                throw new BusinessLogicException('The Purchase Order creator cannot approve or reject their own order; this applies to the current revision maker.');
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

    private function rejectedApproval(PurchaseOrder $order): ApprovalRequest
    {
        $approval = ApprovalRequest::query()
            ->where('approvable_type', PurchaseOrder::class)
            ->where('approvable_id', $order->id)
            ->where('status', 'Rejected')
            ->latest('completed_at')
            ->first();

        if (! $approval) {
            throw new BusinessLogicException('Rejected Purchase Order approval evidence was not found.');
        }

        return $approval;
    }

    private function revisionMakerId(PurchaseOrder $order): string
    {
        $makerId = $order->currentRevisionMakerId();

        if (! $makerId) {
            throw new BusinessLogicException('Purchase Order revision maker evidence is unavailable.');
        }

        return $makerId;
    }

    private function assertRevisionAuthority(User $actor, string $propertyId): Property
    {
        $property = $this->activeProperty();

        if ($property->id !== $propertyId
            || ! $actor->can('purchasing.purchase-order.edit')
            || ! $actor->properties()
                ->where('properties.id', $propertyId)
                ->wherePivot('status', 'active')
                ->exists()) {
            throw new BusinessLogicException('Purchase Order revision requires edit authority and active Property membership.');
        }

        return $property;
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function activeProperty(): Property
    {
        return Property::query()
            ->whereKey($this->currentProperty->resolveOrFail())
            ->where('is_active', true)
            ->firstOrFail();
    }
}
