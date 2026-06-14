<?php

namespace Modules\Foundation\Approval\Services;

use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Foundation\Approval\Repositories\ApprovalMatrixRepository;
use Exception;

class ApprovalRoutingService
{
    public function __construct(
        private ApprovalDelegateService $delegateService,
        private ApprovalMatrixRepository $matrixRepo
    ) {}

    /**
     * Resolves assignees into a flat list of User IDs based on the assignee_type.
     * Returns an array of resolved User IDs.
     */
    public function resolveAssignees(array $stepAssignees, ApprovableContract $document): array
    {
        $resolvedUserIds = [];

        foreach ($stepAssignees as $assignee) {
            switch ($assignee['assignee_type']) {
                case 'USER':
                    if (!empty($assignee['user_id'])) {
                        $resolvedUserIds[] = $this->delegateService->resolveFinalUserId($assignee['user_id']);
                    }
                    break;
                    
                case 'MATRIX_RULE':
                    $rules = $this->matrixRepo->getRulesForDocument(
                        'Purchasing', // Hardcoded for foundation demo purposes, normally extracted from document context
                        class_basename(get_class($document)),
                        $document->getDepartmentId(),
                        $document->getApprovalAmount()
                    );
                    
                    foreach ($rules as $rule) {
                        if ($rule->assignee_type === 'USER' && $rule->user_id) {
                            $resolvedUserIds[] = $this->delegateService->resolveFinalUserId($rule->user_id);
                        }
                        // ROLE, POSITION logic would be implemented here retrieving users holding those keys.
                    }
                    break;
                    
                default:
                    // ROLE, POSITION, DEPARTMENT_HEAD dynamically resolving users not fully implemented in foundation stub
                    break;
            }
        }

        return array_unique(array_filter($resolvedUserIds));
    }
}
