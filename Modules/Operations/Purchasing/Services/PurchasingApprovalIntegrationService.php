<?php

namespace Modules\Operations\Purchasing\Services;

use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Shared\Contracts\ApprovableContract;

class PurchasingApprovalIntegrationService
{
    public function __construct(
        protected ApprovalEngineService $approvalEngine
    ) {
    }

    public function submitPurchaseRequestForApproval(PurchaseRequest $pr, string $userId): void
    {
        $this->approvalEngine->submitForApproval($pr, $userId);
        $pr->update(['status' => 'Pending Approval']);
    }

    public function submitPurchaseOrderForApproval(PurchaseOrder $po, string $userId): void
    {
        $this->approvalEngine->submitForApproval($po, $userId);
        $po->update(['status' => 'Pending Approval']);
    }
}
