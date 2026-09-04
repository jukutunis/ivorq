<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Collection;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Repositories\CostDeliveryCutoverPreflightRepository;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryCutoverRequest;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use RuntimeException;

final class CostDeliveryCutoverPreflightService
{
    public function __construct(private readonly CostDeliveryCutoverPreflightRepository $repository) {}

    /** @return array{period:object,scopes:array<int,array<string,mixed>>} */
    public function prove(
        CostDeliveryCutoverRequest $request,
        CostDeliveryModeOwnership $ownership,
        Collection $pilots,
    ): array {
        if ($pilots->count() !== 1 || (int) $pilots->first()->pilot_slot !== 1
            || $pilots->first()->property_id !== $request->propertyId
            || $pilots->first()->owner_approval_reference !== $request->ownerApprovalReference
            || $pilots->first()->authorized_by !== $request->approvedBy) {
            $this->block('CUTOVER_BLOCKED_PILOT_MISMATCH');
        }

        $group = $this->repository->lockEnrollmentGroup($request->enrollmentGroupId);
        if ($group === null || $group->status !== CostAuthorityEnrollmentStatusEnum::Enrolled
            || $group->property_id !== $request->propertyId || $group->item_id !== $request->itemId) {
            $this->block('CUTOVER_BLOCKED_ENROLLMENT_INVALID');
        }
        if ($ownership->property_id !== $request->propertyId || $ownership->item_id !== $request->itemId
            || $ownership->enrollment_group_id !== $request->enrollmentGroupId
            || $ownership->delivery_mode !== CostDeliveryMode::Synchronous
            || $ownership->activated_cutover_id !== null) {
            $this->block('CUTOVER_BLOCKED_OWNERSHIP_NOT_SYNCHRONOUS');
        }

        $snapshots = $this->repository->lockSnapshots($group->id);
        if ($snapshots->isEmpty()) {
            $this->block('CUTOVER_BLOCKED_SCOPE_SNAPSHOT_INCOMPLETE');
        }
        $seenLocations = [];
        foreach ($snapshots as $snapshot) {
            $expected = "property:{$request->propertyId}:location:{$snapshot->location_id}:item:{$request->itemId}";
            if ($snapshot->valuation_scope !== $expected || isset($seenLocations[$snapshot->location_id])) {
                $this->block('CUTOVER_BLOCKED_SCOPE_SNAPSHOT_INCOMPLETE');
            }
            $seenLocations[$snapshot->location_id] = true;
        }

        if ($this->repository->hasSourcesAtOrAfterBoundary(
            $request->propertyId, $request->itemId, $request->targetFinancialPeriodId, $request->boundaryBusinessDate,
        )) {
            $this->block('CUTOVER_BLOCKED_TARGET_PERIOD_SOURCE_EXISTS');
        }
        if ($this->repository->hasInFlightDocuments($request->propertyId, $request->itemId)) {
            $this->block('CUTOVER_BLOCKED_IN_FLIGHT_DOCUMENT');
        }
        if ($this->repository->hasTerminalPostingEvidenceGap($request->propertyId, $request->itemId)) {
            $this->block('CUTOVER_BLOCKED_TERMINAL_POSTING_EVIDENCE_GAP');
        }
        $sequenceEvidence = [];
        foreach ($snapshots as $snapshot) {
            [$allocator, $positiveSource] = $this->repository->lockScopeSequenceState(
                $request->propertyId, $snapshot->location_id, $request->itemId,
            );
            $sequenceEvidence[$snapshot->id] = [$allocator, $positiveSource];
        }

        if ($this->repository->hasUnclassifiedHistoricalEvidence(
            $request->propertyId, $request->itemId, $request->boundaryBusinessDate,
        )) {
            $this->block('CUTOVER_BLOCKED_HISTORICAL_EVIDENCE_UNCLASSIFIED');
        }
        if ($this->repository->hasUnresolvedDisposition($request->propertyId, $request->itemId)) {
            $this->block('CUTOVER_BLOCKED_UNRESOLVED_DEFERRED_DISPOSITION');
        }
        if (! $this->repository->schemaControlsInstalled()) {
            $this->block('CUTOVER_BLOCKED_SCHEMA_CONTROLS_MISSING');
        }

        $period = $this->repository->lockPeriod($request->targetFinancialPeriodId);
        if ($period === null || $period->property_id !== $request->propertyId) {
            $this->block('CUTOVER_BLOCKED_TARGET_PERIOD_MISSING');
        }
        if ($period->status !== FinancialPeriodStatusEnum::Open) {
            $this->block($period->status === FinancialPeriodStatusEnum::Reopened
                ? 'CUTOVER_BLOCKED_REOPENED_PERIOD'
                : 'CUTOVER_BLOCKED_TARGET_PERIOD_NOT_OPEN');
        }
        $expectedBoundary = sprintf('%04d-%02d-01', $period->period_year, $period->period_month);
        if ($request->boundaryBusinessDate !== $expectedBoundary) {
            $this->block('CUTOVER_BLOCKED_BOUNDARY_NOT_PERIOD_START');
        }
        $prior = $this->repository->lockPriorPeriod($request->propertyId, $period->period_year, $period->period_month);
        if ($prior === null || $prior->status !== FinancialPeriodStatusEnum::Closed) {
            $this->block('CUTOVER_BLOCKED_PRIOR_PERIOD_NOT_CLOSED');
        }
        $businessDate = $this->repository->lockBusinessDate($request->propertyId, $request->boundaryBusinessDate);
        if ($businessDate === null || $businessDate->status !== PropertyBusinessDateStatusEnum::Open
            || ! $businessDate->is_open
            || $businessDate->business_date->format('Y-m-d') !== $request->boundaryBusinessDate) {
            $this->block('CUTOVER_BLOCKED_BUSINESS_DATE_NOT_EXACT_OPEN_BOUNDARY');
        }

        $scopeEvidence = [];
        foreach ($snapshots as $snapshot) {
            [$allocator, $positiveSource] = $sequenceEvidence[$snapshot->id];
            $avco = $this->repository->lockScopeAvcoState(
                $request->propertyId, $snapshot->location_id, $request->itemId,
            );
            if ($avco === null || $avco->enrollment_group_id !== $group->id
                || $avco->enrollment_scope_snapshot_id !== $snapshot->id
                || $avco->valuation_scope !== $snapshot->valuation_scope) {
                $this->block('CUTOVER_BLOCKED_SCOPE_SNAPSHOT_INCOMPLETE');
            }
            $allocatorSequence = $allocator === null ? 0 : (int) $allocator->last_sequence;
            $avcoSequence = $avco->last_valuation_sequence === null ? null : (int) $avco->last_valuation_sequence;
            $virgin = $allocatorSequence === 0 && $avcoSequence === null && ! $positiveSource;
            $nonVirgin = $allocator !== null && $allocatorSequence > 0 && $avcoSequence === $allocatorSequence;
            if (! $virgin && ! $nonVirgin) {
                $this->block('CUTOVER_BLOCKED_SEQUENCE_STATE_DIVERGENCE');
            }
            $scopeEvidence[] = [
                'enrollment_scope_snapshot_id' => $snapshot->id,
                'property_id' => $request->propertyId,
                'location_id' => $snapshot->location_id,
                'item_id' => $request->itemId,
                'valuation_scope' => $snapshot->valuation_scope,
                'inventory_sequence_source' => $allocator === null ? 'ALLOCATOR_ABSENT' : 'ALLOCATOR_ROW',
                'inventory_valuation_sequence_id' => $allocator?->id,
                'inventory_allocator_last_sequence' => $allocatorSequence,
                'cost_avco_last_valuation_sequence' => $avcoSequence,
                'sequence_state_classification' => $virgin
                    ? 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE'
                    : 'PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => $allocatorSequence,
                'first_deferred_owned_sequence' => $allocatorSequence + 1,
            ];
        }

        return ['period' => $period, 'scopes' => $scopeEvidence];
    }

    private function block(string $reason): never
    {
        throw new RuntimeException($reason);
    }
}
