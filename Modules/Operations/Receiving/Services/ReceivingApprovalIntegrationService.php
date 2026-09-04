<?php

namespace Modules\Operations\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Operations\Inventory\Services\InventoryDocumentMutationGate;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Shared\Exceptions\BusinessLogicException;

class ReceivingApprovalIntegrationService
{
    public function __construct(
        protected ApprovalEngineService $approvalEngineService,
        protected InventoryReceiptIntegrationService $inventoryIntegrationService,
        protected InventoryDocumentMutationGate $inventoryDocumentMutationGate,
    ) {}

    public function submitForApproval(ReceivingDocument $document): void
    {
        try {
            $this->approvalEngineService->submitForApproval($document, $document->created_by);
        } catch (\Exception $e) {
            throw new BusinessLogicException('Failed to submit to Approval Engine: '.$e->getMessage());
        }
    }

    public function handleApproval(ReceivingDocument $document, ?string $approverId = null): void
    {
        DB::transaction(function () use ($document, $approverId) {
            $this->lockDocumentItems($document);
            $document->update(['status' => ReceivingDocumentStatusEnum::Approved->value]);

            // Sync to inventory if applicable
            $this->inventoryIntegrationService->syncToInventory($document, $approverId);
        });
    }

    public function handleRejection(ReceivingDocument $document, string $reason): void
    {
        DB::transaction(function () use ($document, $reason): void {
            $this->lockDocumentItems($document);
            $document->update([
                'status' => ReceivingDocumentStatusEnum::Rejected->value,
                'remarks' => $document->remarks ? $document->remarks."\nRejected: ".$reason : 'Rejected: '.$reason,
            ]);
        });
    }

    private function lockDocumentItems(ReceivingDocument $document): void
    {
        $this->inventoryDocumentMutationGate->lock(
            (string) $document->property_id,
            $document->lines()->pluck('inventory_item_id')->all(),
        );
    }
}
