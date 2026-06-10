<?php

namespace Modules\Foundation\Approval\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Approval\Enums\ApprovalActionEnum;
use Modules\Foundation\Approval\Models\ApprovalSnapshot;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Repositories\ApprovalWorkflowRepository;

class ApprovalEngineService
{
    public function __construct(
        protected ApprovalWorkflowRepository $workflowRepository
    ) {}

    /**
     * Submit document and return true if a workflow was found and requires approval.
     */
    public function submitDocument(Model $document, string $module): ?ApprovalStep
    {
        $workflow = $this->workflowRepository->findMatchingWorkflow($document->property_id, $module);
        
        if (!$workflow) {
            return null; // Auto approve or no workflow required
        }

        return $workflow->steps()->where('sequence_no', 1)->first();
    }

    /**
     * Approve document step. Returns next step if more approvals required, or null if fully approved.
     */
    public function approve(
        Model $document, 
        string $module, 
        float $amount, 
        string $approverId, 
        string $approverName, 
        ?string $roleName, 
        ?string $remarks = null
    ): ?ApprovalStep {
        $workflow = $this->workflowRepository->findMatchingWorkflow($document->property_id, $module);
        
        if (!$workflow) {
            throw new Exception("No active approval workflow found for module: {$module}");
        }

        $lastSnapshot = ApprovalSnapshot::where('reference_type', get_class($document))
            ->where('reference_id', $document->id)
            ->where('action', ApprovalActionEnum::Approved)
            ->orderByDesc('sequence_no')
            ->first();

        $nextSequence = $lastSnapshot ? $lastSnapshot->sequence_no + 1 : 1;

        $step = $workflow->steps()->where('sequence_no', $nextSequence)->first();

        if (!$step) {
            throw new Exception("No further approval steps found to approve.");
        }

        ApprovalSnapshot::create([
            'reference_type' => get_class($document),
            'reference_id' => $document->id,
            'workflow_id' => $workflow->id,
            'sequence_no' => $step->sequence_no,
            'approver_id' => $approverId,
            'approver_name' => $approverName,
            'role_name' => $roleName,
            'approval_limit' => $step->approval_limit,
            'action' => ApprovalActionEnum::Approved,
            'remarks' => $remarks,
        ]);

        // Check if fully approved by current limit
        if ($step->approval_limit !== null && $amount <= $step->approval_limit) {
            return null; // Fully approved
        }

        // Otherwise check next step
        $nextStep = $workflow->steps()->where('sequence_no', $step->sequence_no + 1)->first();
        
        return $nextStep; // If null, means no more steps and fully approved
    }

    /**
     * Reject a document.
     */
    public function reject(
        Model $document, 
        string $module, 
        string $approverId, 
        string $approverName, 
        ?string $roleName, 
        ?string $remarks = null
    ): void {
        $workflow = $this->workflowRepository->findMatchingWorkflow($document->property_id, $module);
        
        ApprovalSnapshot::create([
            'reference_type' => get_class($document),
            'reference_id' => $document->id,
            'workflow_id' => $workflow ? $workflow->id : null,
            'sequence_no' => null,
            'approver_id' => $approverId,
            'approver_name' => $approverName,
            'role_name' => $roleName,
            'approval_limit' => null,
            'action' => ApprovalActionEnum::Rejected,
            'remarks' => $remarks,
        ]);
    }
}
