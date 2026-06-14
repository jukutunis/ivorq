<?php

namespace Modules\Foundation\Approval\Services;

use Carbon\Carbon;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Repositories\ApprovalRequestRepository;
use Modules\Foundation\Approval\Events\ApprovalEscalated;
use Modules\Foundation\Approval\Events\ApprovalExpired;

class ApprovalEscalationService
{
    public function __construct(private ApprovalRequestRepository $requestRepository)
    {}

    public function checkEscalations(): void
    {
        $this->processPendingEscalations();
        $this->processExpirations();
    }

    private function processPendingEscalations(): void
    {
        $pendingRequests = $this->requestRepository->getRequestsPendingEscalation();

        foreach ($pendingRequests as $request) {
            $stepSnapshot = collect($request->step_snapshot)->firstWhere('id', $request->current_step_id);
            if (!$stepSnapshot || !isset($stepSnapshot['timeout_hours']) || empty($stepSnapshot['timeout_hours'])) {
                continue;
            }

            $timeoutHours = $stepSnapshot['timeout_hours'];
            $deadline = Carbon::parse($request->updated_at)->addHours($timeoutHours);

            if (now()->greaterThan($deadline)) {
                $request->status = 'Escalated';
                $request->save();
                
                $step = ApprovalStep::find($request->current_step_id);
                if ($step) {
                    ApprovalEscalated::dispatch($request, $step);
                }
            }
        }
    }

    private function processExpirations(): void
    {
        $escalatedRequests = $this->requestRepository->getRequestsPendingExpiration();

        foreach ($escalatedRequests as $request) {
            // Hardcode 48 hours grace period for now
            $expirationDeadline = Carbon::parse($request->updated_at)->addHours(48);

            if (now()->greaterThan($expirationDeadline)) {
                $request->status = 'Expired';
                $request->save();

                ApprovalExpired::dispatch($request);
            }
        }
    }
}
