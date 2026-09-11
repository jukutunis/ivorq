<?php

namespace Modules\Foundation\Approval\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Approval\Models\ApprovalAction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Repositories\ApprovalWorkflowRepository;
use Modules\Foundation\User\Models\User;

class ApprovalEngineService
{
    public function __construct(
        private ApprovalWorkflowRepository $workflowRepo,
        private ApprovalSnapshotService $snapshotService,
        private ApprovalRoutingService $routingService
    ) {}

    /**
     * Initiates a new approval request for a given document.
     */
    public function submitForApproval(ApprovableContract $document, string $requesterId): ApprovalRequest
    {
        return DB::transaction(function () use ($document, $requesterId) {
            $workflow = $this->workflowRepo->getActiveForApprovableType($document->getApprovableType());

            if (! $workflow) {
                throw new Exception('No active approval workflow found for '.$document->getApprovableType());
            }

            if ($workflow->steps->isEmpty()) {
                throw new Exception('Approval workflow has no steps.');
            }

            $request = ApprovalRequest::create([
                'property_id' => $document->getPropertyId(),
                'approvable_type' => $document->getApprovableType(),
                'approvable_id' => $document->getApprovableId(),
                'workflow_id' => $workflow->id,
                'requester_id' => $requesterId,
                'status' => 'Pending',
                'requested_at' => now(),
            ]);

            $this->snapshotService->createSnapshot($request, $workflow);

            // Initiate the first step
            $firstStep = $workflow->steps->first();
            $request->update(['current_step_id' => $firstStep->id]);

            // Dispatch Events (which triggers Notifications)
            event(new ApprovalRequested($request));

            return $request;
        });
    }

    /**
     * Records a user's approval action and checks for quorum.
     */
    public function approve(ApprovalRequest $request, string $userId, ?string $notes = null): void
    {
        DB::transaction(function () use ($request, $userId, $notes) {
            // Pessimistic Locking
            $request = ApprovalRequest::where('id', $request->id)->lockForUpdate()->first();

            if (! in_array($request->status, ['Pending', 'In Progress'])) {
                throw new Exception('Approval request is not pending.');
            }

            $user = User::find($userId);
            if (! $user || ! $user->properties()->where('property_id', $request->property_id)->exists()) {
                throw new Exception('User does not belong to the requested property');
            }

            ApprovalAction::create([
                'approval_request_id' => $request->id,
                'approval_step_id' => $request->current_step_id,
                'user_id' => $userId,
                'action_type' => 'approve',
                'notes' => $notes,
                'ip_address' => request()->ip(),
            ]);

            // Check if step quorum is met based on snapshot
            $stepSnapshot = collect($request->step_snapshot)->firstWhere('id', $request->current_step_id);
            $requiredApprovals = $stepSnapshot['required_approvals'] ?? 1;

            $currentApprovals = ApprovalAction::where('approval_request_id', $request->id)
                ->where('approval_step_id', $request->current_step_id)
                ->where('action_type', 'approve')
                ->count();

            if ($currentApprovals >= $requiredApprovals) {
                // Move to next step or complete
                $this->advanceToNextStep($request, $stepSnapshot);
            } else {
                $request->update(['status' => 'In Progress']);
            }
        });
    }

    public function reject(ApprovalRequest $request, string $userId, ?string $notes = null): void
    {
        DB::transaction(function () use ($request, $userId, $notes) {
            $request = ApprovalRequest::where('id', $request->id)->lockForUpdate()->first();

            if (! in_array($request->status, ['Pending', 'In Progress'])) {
                throw new Exception('Approval request is not pending.');
            }

            ApprovalAction::create([
                'approval_request_id' => $request->id,
                'approval_step_id' => $request->current_step_id,
                'user_id' => $userId,
                'action_type' => 'reject',
                'notes' => $notes,
                'ip_address' => request()->ip(),
            ]);

            $request->update([
                'status' => 'Rejected',
                'completed_at' => now(),
            ]);

            $request->approvable->markAsRejected($notes);
            event(new ApprovalRejected($request));
        });
    }

    public function cancel(ApprovalRequest $request, string $userId, string $reason): void
    {
        DB::transaction(function () use ($request, $userId, $reason) {
            $lockedRequest = ApprovalRequest::where('id', $request->id)->lockForUpdate()->first();

            if (! $lockedRequest) {
                throw new Exception('Approval request not found.');
            }

            if (! in_array($lockedRequest->status, ['Pending', 'In Progress'], true)) {
                throw new Exception('Approval request is not active.');
            }

            if (! $lockedRequest->current_step_id) {
                throw new Exception('Approval request has no active step.');
            }

            $actor = User::find($userId);

            if (! $actor) {
                throw new Exception('Cancellation actor not found.');
            }

            if ($actor->id !== $lockedRequest->requester_id && ! $actor->isSuperAdmin()) {
                throw new Exception('User is not authorized to cancel this approval request.');
            }

            $normalizedReason = trim($reason);

            if ($normalizedReason === '') {
                throw new Exception('Cancellation reason is required.');
            }

            $activeStepId = $lockedRequest->current_step_id;

            ApprovalAction::create([
                'approval_request_id' => $lockedRequest->id,
                'approval_step_id' => $activeStepId,
                'user_id' => $actor->id,
                'action_type' => 'cancel',
                'notes' => $normalizedReason,
                'ip_address' => request()->ip(),
            ]);

            $lockedRequest->update([
                'status' => 'Cancelled',
                'completed_at' => now(),
                'current_step_id' => null,
            ]);

            event(new ApprovalCancelled($lockedRequest));
        });
    }

    private function advanceToNextStep(ApprovalRequest $request, array $currentStepSnapshot): void
    {
        $allSteps = collect($request->step_snapshot)->sortBy('sequence');
        $currentIndex = $allSteps->search(function ($item) use ($currentStepSnapshot) {
            return $item['id'] === $currentStepSnapshot['id'];
        });

        $nextStep = $allSteps->get($currentIndex + 1);

        if ($nextStep) {
            $request->update([
                'current_step_id' => $nextStep['id'],
                'status' => 'Pending',
            ]);
            event(new ApprovalRequested($request));
        } else {
            $request->update([
                'status' => 'Approved',
                'completed_at' => now(),
                'current_step_id' => null,
            ]);
            $request->approvable->markAsApproved();
            event(new ApprovalApproved($request));
        }
    }
}
