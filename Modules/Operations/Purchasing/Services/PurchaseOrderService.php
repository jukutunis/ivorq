<?php

namespace Modules\Operations\Purchasing\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Repositories\PurchaseOrderRepository;
use Modules\Operations\Purchasing\Repositories\PurchaseRequestRepository;
use Shared\Exceptions\BusinessLogicException;

class PurchaseOrderService
{
    public function __construct(
        protected PurchaseOrderRepository $repository,
        protected PurchaseRequestRepository $prRepository
    ) {
    }

    public function createFromApprovedPR(string $prId, string $vendorId, array $data, User $user): PurchaseOrder
    {
        $pr = $this->prRepository->findOrFail($prId);

        // BR-001 & BR-002: Only from Approved Purchase Request
        if ($pr->status->value !== PurchaseRequestStatusEnum::Approved->value) {
            throw new BusinessLogicException('Purchase Order can only be created from an Approved Purchase Request.');
        }

        // BR-007: One Purchase Request can only generate one Purchase Order
        if (PurchaseOrder::where('purchase_request_id', $prId)->exists()) {
            throw new BusinessLogicException('A Purchase Order has already been created for this Purchase Request.');
        }

        // BR-003: Vendor must be active (and approved)
        $vendor = Vendor::findOrFail($vendorId);
        if (! $vendor->is_active || ! $vendor->is_approved) {
            throw new BusinessLogicException('Selected Vendor is either inactive or not approved (blacklisted).');
        }

        // Generate PO Number (BR-004)
        $year = now()->format('Y');
        $lastPo = PurchaseOrder::where('property_id', $user->property_id)
            ->whereYear('created_at', $year)
            ->latest('id')
            ->first();
            
        $nextId = 1;
        if ($lastPo && preg_match('/-(\d+)$/', $lastPo->po_no, $matches)) {
            $nextId = intval($matches[1]) + 1;
        }
        $poNo = sprintf('PO-%s-%06d', $year, $nextId);

        return DB::transaction(function () use ($pr, $vendor, $data, $poNo, $user) {
            $subtotal = 0;
            $taxAmount = 0; // Keeping simple for foundation
            $totalAmount = 0;

            $po = $this->repository->create([
                'property_id' => $user->property_id,
                'po_no' => $poNo,
                'vendor_id' => $vendor->id,
                'purchase_request_id' => $pr->id,
                'issue_date' => now(),
                'expected_delivery_date' => $data['expected_delivery_date'],
                'currency_code' => $pr->currency_code,
                'exchange_rate' => $pr->exchange_rate,
                'status' => PurchaseOrderStatusEnum::Draft->value,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            foreach ($pr->lines as $line) {
                // By default copy PR lines to PO lines
                $quantity = $line->quantity;
                $unitCost = $line->estimated_unit_cost;
                $lineTotal = $quantity * $unitCost;
                $subtotal += $lineTotal;

                $po->lines()->create([
                    'purchase_request_line_id' => $line->id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'description' => $line->description,
                    'quantity_ordered' => $quantity,
                    'quantity_received' => 0, // BR-005
                    'unit_id' => $line->unit_id,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            }

            // Update PO totals
            $totalAmount = $subtotal + $taxAmount;
            $po->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
            ]);

            return $po->fresh(['vendor', 'lines', 'purchaseRequest']);
        });
    }

    public function update(string $id, array $data, User $user, ?array $lines = null): PurchaseOrder
    {
        $po = $this->repository->findOrFail($id);

        // BR-006: Cannot edit if PartiallyReceived or FullyReceived
        if (in_array($po->status->value, [
            PurchaseOrderStatusEnum::PartiallyReceived->value, 
            PurchaseOrderStatusEnum::FullyReceived->value,
            PurchaseOrderStatusEnum::Closed->value,
            PurchaseOrderStatusEnum::Cancelled->value,
        ])) {
            throw new BusinessLogicException('Purchase Order cannot be edited in its current status.');
        }

        return DB::transaction(function () use ($po, $data, $lines, $user) {
            $data['updated_by'] = $user->id;
            
            if ($lines !== null) {
                // For simplicity in foundation, just clear and recreate lines if provided
                // Or update them. Let's update existing lines and calculate total.
                $subtotal = 0;
                foreach ($lines as $lineData) {
                    $poLine = $po->lines()->findOrFail($lineData['id']);
                    $quantity = $lineData['quantity_ordered'] ?? $poLine->quantity_ordered;
                    $unitCost = $lineData['unit_cost'] ?? $poLine->unit_cost;
                    $lineTotal = $quantity * $unitCost;
                    
                    $poLine->update([
                        'quantity_ordered' => $quantity,
                        'unit_cost' => $unitCost,
                        'line_total' => $lineTotal,
                        'updated_by' => $user->id,
                    ]);
                    $subtotal += $lineTotal;
                }
                
                $data['subtotal'] = $subtotal;
                $data['total_amount'] = $subtotal + $po->tax_amount;
            }

            return $this->repository->update($po->id, $data);
        });
    }

    public function issue(string $id, User $user): PurchaseOrder
    {
        $po = $this->repository->findOrFail($id);

        if ($po->status->value !== PurchaseOrderStatusEnum::Draft->value) {
            throw new BusinessLogicException('Only Draft Purchase Orders can be issued.');
        }

        $po->update([
            'status' => PurchaseOrderStatusEnum::Issued->value,
            'updated_by' => $user->id,
        ]);

        return $po->fresh();
    }

    public function cancel(string $id, User $user): PurchaseOrder
    {
        $po = $this->repository->findOrFail($id);

        if (in_array($po->status->value, [
            PurchaseOrderStatusEnum::PartiallyReceived->value, 
            PurchaseOrderStatusEnum::FullyReceived->value,
            PurchaseOrderStatusEnum::Closed->value,
            PurchaseOrderStatusEnum::Cancelled->value,
        ])) {
            throw new BusinessLogicException('Purchase Order cannot be cancelled from its current status.');
        }

        $po->update([
            'status' => PurchaseOrderStatusEnum::Cancelled->value,
            'updated_by' => $user->id,
        ]);

        return $po->fresh();
    }
}
