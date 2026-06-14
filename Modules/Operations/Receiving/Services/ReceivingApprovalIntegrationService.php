<?php

namespace Modules\Operations\Receiving\Services;

use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Shared\Exceptions\BusinessLogicException;

class ReceivingApprovalIntegrationService
{
    public function __construct(
        protected ApprovalEngineService $approvalEngineService,
        protected InventoryReceiptIntegrationService $inventoryIntegrationService
    ) {}

    public function submitForApproval(ReceivingDocument $document): void
    {
        try {
            $this->approvalEngineService->submitForApproval($document, $document->created_by);
        } catch (\Exception $e) {
            throw new BusinessLogicException("Failed to submit to Approval Engine: " . $e->getMessage());
        }
    }

    public function handleApproval(ReceivingDocument $document): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($document) {
            $document->update(['status' => \Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum::Approved->value]);
            
            // Sync to inventory if applicable
            $this->inventoryIntegrationService->syncToInventory($document);
        });
    }

    public function handleRejection(ReceivingDocument $document, string $reason): void
    {
        $document->update([
            'status' => \Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum::Rejected->value,
            'remarks' => $document->remarks ? $document->remarks . "\nRejected: " . $reason : "Rejected: " . $reason
        ]);
    }
}
