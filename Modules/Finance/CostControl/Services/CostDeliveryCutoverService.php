<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostDeliveryCutover;
use Modules\Finance\CostControl\Models\CostDeliveryCutoverAttempt;
use Modules\Finance\CostControl\Repositories\CostDeliveryCutoverPreflightRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryCutoverRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryCutoverRequest;
use RuntimeException;
use Throwable;

final class CostDeliveryCutoverService
{
    public function __construct(
        private readonly CostDeliveryModeOwnershipRepository $ownershipRepository,
        private readonly CostDeliveryCutoverPreflightRepository $preflightRepository,
        private readonly CostDeliveryCutoverPreflightService $preflightService,
        private readonly CostDeliveryCutoverRepository $cutoverRepository,
    ) {}

    public function activateGroup(CostDeliveryCutoverRequest $request): CostDeliveryCutover
    {
        try {
            return DB::transaction(function () use ($request): CostDeliveryCutover {
                $existing = $this->preflightRepository->findAttemptForUpdate($request->requestId);
                if ($existing !== null) {
                    return $this->resolveExisting($request, $existing);
                }

                $pilots = $this->preflightRepository->lockPilotRows();

                // The ownership row is the posting/document/cutover serialization latch.
                $ownership = $this->ownershipRepository->findForUpdateByPropertyItem(
                    $request->propertyId, $request->itemId,
                );
                if ($ownership === null) {
                    throw new RuntimeException('CUTOVER_BLOCKED_OWNERSHIP_MISSING');
                }

                // Recheck request identity after waiting for ownership: a concurrent
                // identical request may have completed while this transaction waited.
                $existing = $this->preflightRepository->findAttemptForUpdate($request->requestId);
                if ($existing !== null) {
                    return $this->resolveExisting($request, $existing);
                }

                $proof = $this->preflightService->prove($request, $ownership, $pilots);
                $now = now();
                $cutover = $this->cutoverRepository->insertCutoverEvidence([
                    'ownership_id' => $ownership->id,
                    'enrollment_group_id' => $request->enrollmentGroupId,
                    'property_id' => $request->propertyId,
                    'item_id' => $request->itemId,
                    'financial_period_id' => $request->targetFinancialPeriodId,
                    'boundary_business_date' => $request->boundaryBusinessDate,
                    'owner_approval_reference' => $request->ownerApprovalReference,
                    'requested_by' => $request->requestedBy,
                    'requested_at' => $now,
                    'approved_by' => $request->approvedBy,
                    'approved_at' => $now,
                    'activated_by' => $request->approvedBy,
                    'activated_at' => $now,
                ]);
                foreach ($proof['scopes'] as $scope) {
                    $this->cutoverRepository->insertScopeEvidence($scope + ['cutover_id' => $cutover->id]);
                }

                $ownership->delivery_mode = CostDeliveryMode::Deferred;
                $ownership->ownership_version = (int) $ownership->ownership_version + 1;
                $ownership->activated_cutover_id = $cutover->id;
                $ownership->changed_by = $request->approvedBy;
                $ownership->changed_at = $now;
                $ownership->save();

                $this->cutoverRepository->insertAttemptEvidence($this->attemptAttributes(
                    $request, 'ACTIVATED', null, $cutover->id, $now,
                ));

                return $cutover->fresh('scopes');
            }, 1);
        } catch (Throwable $failure) {
            if ($failure instanceof RuntimeException
                && $failure->getMessage() === 'CUTOVER_REQUEST_ID_CONFLICT') {
                throw $failure;
            }

            $reason = $this->stableReason($failure);
            try {
                DB::transaction(function () use ($request, $reason): void {
                    $existing = CostDeliveryCutoverAttempt::where('request_id', $request->requestId)
                        ->lockForUpdate()->first();
                    if ($existing !== null) {
                        $this->assertExactRequest($request, $existing);

                        return;
                    }
                    $this->cutoverRepository->insertAttemptEvidence($this->attemptAttributes(
                        $request, 'CUTOVER_BLOCKED', $reason, null, now(),
                    ));
                }, 1);
            } catch (Throwable $recordingFailure) {
                // A concurrent request-id winner is resolved exactly; otherwise
                // preserve the original stable activation failure.
                $existing = CostDeliveryCutoverAttempt::where('request_id', $request->requestId)->first();
                if ($existing !== null) {
                    $this->assertExactRequest($request, $existing);
                }
            }

            throw new RuntimeException($reason, 0, $failure);
        }
    }

    private function resolveExisting(
        CostDeliveryCutoverRequest $request,
        CostDeliveryCutoverAttempt $attempt,
    ): CostDeliveryCutover {
        $this->assertExactRequest($request, $attempt);
        if ($attempt->outcome === 'ACTIVATED' && $attempt->cutover_id !== null) {
            $cutover = CostDeliveryCutover::with('scopes')->findOrFail($attempt->cutover_id);
            if ($cutover->approved_by !== $request->approvedBy) {
                throw new RuntimeException('CUTOVER_REQUEST_ID_CONFLICT');
            }

            return $cutover;
        }
        throw new RuntimeException((string) $attempt->reason_code);
    }

    private function assertExactRequest(
        CostDeliveryCutoverRequest $request,
        CostDeliveryCutoverAttempt $attempt,
    ): void {
        $exact = $attempt->property_id === $request->propertyId
            && $attempt->item_id === $request->itemId
            && $attempt->enrollment_group_id === $request->enrollmentGroupId
            && $attempt->target_financial_period_id === $request->targetFinancialPeriodId
            && $attempt->boundary_business_date?->format('Y-m-d') === $request->boundaryBusinessDate
            && $attempt->owner_approval_reference === $request->ownerApprovalReference
            && $attempt->requested_by === $request->requestedBy;
        if (! $exact) {
            throw new RuntimeException('CUTOVER_REQUEST_ID_CONFLICT');
        }
    }

    private function attemptAttributes(
        CostDeliveryCutoverRequest $request,
        string $outcome,
        ?string $reason,
        ?string $cutoverId,
        mixed $requestedAt,
    ): array {
        return [
            'request_id' => $request->requestId,
            'property_id' => $request->propertyId,
            'item_id' => $request->itemId,
            'enrollment_group_id' => $request->enrollmentGroupId,
            'target_financial_period_id' => $request->targetFinancialPeriodId,
            'boundary_business_date' => $request->boundaryBusinessDate,
            'outcome' => $outcome,
            'reason_code' => $reason,
            'cutover_id' => $cutoverId,
            'owner_approval_reference' => $request->ownerApprovalReference,
            'requested_by' => $request->requestedBy,
            'requested_at' => $requestedAt,
        ];
    }

    private function stableReason(Throwable $failure): string
    {
        $message = trim($failure->getMessage());
        if (preg_match('/^(CUTOVER_BLOCKED_[A-Z0-9_]+)$/', $message) === 1) {
            return $message;
        }
        if ($failure instanceof QueryException) {
            return 'CUTOVER_BLOCKED_DATABASE_CONSTRAINT';
        }

        return 'CUTOVER_BLOCKED_INTERNAL';
    }
}
