<?php

namespace Modules\SalesAndEventManagement\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\SalesAndEventManagement\Models\BEOIssueLog;
use Modules\SalesAndEventManagement\Models\EventFunction;
use Modules\SalesAndEventManagement\Enums\BEOStatusEnum;

class IssueBEOAction
{
    public function __construct(
        protected BEOGovernanceGuard $governanceGuard,
        protected BEONumberGenerator $numberGenerator
    ) {}

    /**
     * @param EventFunction $function
     * @param string $companyId
     * @param string $propertyId
     * @param string $propertyCode
     * @param string $issuerId
     * @param array $departmentIds
     * @return BEOIssueLog
     */
    public function execute(
        EventFunction $function,
        string $companyId,
        string $propertyId,
        string $propertyCode,
        string $issuerId,
        string $approverId,
        array $departmentIds = []
    ): BEOIssueLog {
        return DB::transaction(function () use ($function, $companyId, $propertyId, $propertyCode, $issuerId, $approverId, $departmentIds) {
            
            // Get the previous published/approved issue to supersede it
            $previousIssue = BEOIssueLog::where('function_id', $function->id)
                ->whereIn('status', [BEOStatusEnum::PUBLISHED, BEOStatusEnum::APPROVED])
                ->latest('revision_number')
                ->first();

            $revisionNumber = $previousIssue ? $previousIssue->revision_number + 1 : 0;
            
            // Generate snapshot
            // In a real scenario, this would load all relations (Schedule, FB, AV, Setup)
            $function->load('event.opportunity');
            $snapshotPayload = $function->toArray();
            $snapshotHash = hash('sha256', json_encode($snapshotPayload));

            $issueNumber = $this->numberGenerator->generate($function, $propertyCode, $revisionNumber);

            // Create new issue
            $newIssue = new BEOIssueLog([
                'company_id' => $companyId,
                'property_id' => $propertyId,
                'function_id' => $function->id,
                'issue_number' => $issueNumber,
                'revision_number' => $revisionNumber,
                'status' => BEOStatusEnum::PUBLISHED, // Directly publishing for this action
                'snapshot_payload' => $snapshotPayload,
                'snapshot_hash' => $snapshotHash,
                'previous_issue_id' => $previousIssue?->id,
                'issued_at' => now(),
                'issued_by' => $issuerId,
                'approved_at' => now(),
                'approved_by' => $approverId,
                'created_by' => $issuerId,
                'updated_by' => $issuerId,
            ]);

            // Enforce Governance
            $functionPropertyId = $function->event->opportunity->property_id;
            $this->governanceGuard->enforcePropertyIsolation($propertyId, $functionPropertyId);
            $this->governanceGuard->enforceRevisionChainIntegrity($newIssue, $previousIssue);
            $this->governanceGuard->enforceCreatorIsNotApprover($newIssue, $approverId);

            $newIssue->save();

            // Mark previous as superseded
            if ($previousIssue) {
                $previousIssue->status = BEOStatusEnum::SUPERSEDED;
                $previousIssue->updated_by = $issuerId;
                $previousIssue->save();
            }

            // Generate Acknowledgement requests
            if (!empty($departmentIds)) {
                $distribution = $newIssue->distributions()->create([
                    'company_id' => $companyId,
                    'property_id' => $propertyId,
                    'status' => 'DISTRIBUTED',
                    'severity' => 'MINOR',
                    'distributed_by' => $issuerId,
                    'distributed_at' => now(),
                ]);

                foreach ($departmentIds as $departmentId) {
                    $distribution->acknowledgements()->create([
                        'department_id' => $departmentId,
                        'status' => 'PENDING',
                    ]);
                }
            }

            return $newIssue;
        });
    }
}
