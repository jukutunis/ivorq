<?php

namespace Modules\Operations\Receiving\Services;

use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Shared\Exceptions\BusinessLogicException;

class ReceivingApprovalIntegrationService
{
    public function submitForApproval(ReceivingDocument $document): void
    {
        // Integration with Approval Engine
        // This will be called if document exceeds threshold or has discrepancies
    }

    public function handleApproval(ReceivingDocument $document): void
    {
        $document->update(['status' => \Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum::Approved->value]);
    }

    public function handleRejection(ReceivingDocument $document, string $reason): void
    {
        $document->update([
            'status' => \Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum::Rejected->value,
            'remarks' => $document->remarks ? $document->remarks . "\nRejected: " . $reason : "Rejected: " . $reason
        ]);
    }
}
