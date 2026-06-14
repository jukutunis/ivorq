<?php

namespace Modules\Foundation\Approval\Services;

use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalRequest;

class ApprovalSnapshotService
{
    public function createSnapshot(ApprovalRequest $request, ApprovalWorkflow $workflow): void
    {
        $workflowArray = $workflow->toArray();
        unset($workflowArray['steps']); // keep root clean
        
        $stepsArray = $workflow->steps->map(function ($step) {
            $stepData = $step->toArray();
            $stepData['assignees'] = $step->assignees->toArray();
            return $stepData;
        })->toArray();

        $request->update([
            'workflow_snapshot' => $workflowArray,
            'step_snapshot' => $stepsArray,
        ]);
    }
}
