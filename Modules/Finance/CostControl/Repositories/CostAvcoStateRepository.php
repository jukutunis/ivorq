<?php

namespace Modules\Finance\CostControl\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\ValueObjects\AvcoDecimal;
use Modules\Finance\CostControl\ValueObjects\AvcoValuationState;
use Modules\Finance\CostControl\ValueObjects\ValuationSequence;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingDecision;
use Modules\Finance\CostControl\ValueObjects\CostLedgerPostingPlan;

class CostAvcoStateRepository
{
    /**
     * Bootstrap the AVCO state row for a scope if it does not exist, then
     * lock and return it for use by the planner.
     *
     * This method MUST be called inside an active outer database transaction.
     * It mirrors the PostgreSQL-safe pattern from InventoryValuationSequenceRepository:
     *   1. INSERT ... ON CONFLICT DO NOTHING (insertOrIgnore)
     *   2. SELECT ... FOR UPDATE (lockForUpdate after bootstrap)
     *
     * Prohibited patterns:
     *   - firstOrCreate(...)->lockForUpdate()   — not PostgreSQL race-safe
     *   - MAX(sequence) + 1
     *   - direct operational stock-record lookup
     *   - inferred scope identity
     *   - state mutation without a prior locked row
     */
    public function bootstrapAndLock(
        string $propertyId,
        string $locationId,
        string $itemId,
        string $valuationScope
    ): CostAvcoState {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::bootstrapAndLock requires an active outer transaction.'
            );
        }

        // 1. PostgreSQL-safe insert-if-absent (ON CONFLICT DO NOTHING via insertOrIgnore)
        //    Initial values are exactly: qty=0, value=0, wauc=null, seq=null, date=null, provisional=0
        DB::table('cost_avco_states')->insertOrIgnore([
            'id'                              => (string) Str::ulid(),
            'property_id'                     => $propertyId,
            'location_id'                     => $locationId,
            'item_id'                         => $itemId,
            'valuation_scope'                 => $valuationScope,
            'on_hand_quantity'                => '0.0000',
            'carrying_value'                  => '0.0000',
            'weighted_average_unit_cost'      => null,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence'         => null,
            'last_valuation_business_date'    => null,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        // 2. Reload the exact scope row under SELECT ... FOR UPDATE
        $state = CostAvcoState::where('property_id', $propertyId)
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if ($state === null) {
            throw new RuntimeException(
                'CostAvcoStateRepository: failed to resolve AVCO state row after bootstrap.'
            );
        }

        return $state;
    }

    /**
     * Convert a locked CostAvcoState database row into an AvcoValuationState
     * value object, ready to be passed to the planner.
     *
     * No database write occurs here.
     */
    public function toValuationState(CostAvcoState $row): AvcoValuationState
    {
        $lastAppliedSequence = null;

        if ($row->last_valuation_sequence !== null && $row->last_valuation_business_date !== null) {
            $lastAppliedSequence = new ValuationSequence(
                propertyId:       $row->property_id,
                itemId:           $row->item_id,
                valuationScope:   $row->valuation_scope,
                businessDate:     $row->last_valuation_business_date->format('Y-m-d'),
                ledgerSequence:   (int) $row->last_valuation_sequence,
            );
        }

        return new AvcoValuationState(
            propertyId:                    $row->property_id,
            itemId:                        $row->item_id,
            valuationScope:                $row->valuation_scope,
            onHandQuantity:                new AvcoDecimal((string) $row->on_hand_quantity),
            weightedAverageUnitCost:       $row->weighted_average_unit_cost !== null
                                               ? new AvcoDecimal((string) $row->weighted_average_unit_cost)
                                               : null,
            carryingValue:                 new AvcoDecimal((string) $row->carrying_value),
            lastAppliedSequence:           $lastAppliedSequence,
            unresolvedProvisionalQuantity: new AvcoDecimal((string) $row->unresolved_provisional_quantity),
        );
    }

    /**
     * Persist the resulting AVCO state only when the supplied plan has an
     * exact allow decision.
     *
     * Strict Sequence Barrier enforcement:
     *   allow              → persist resultingState, advance last_valuation_sequence
     *   pending            → throw InvalidArgumentException before any DB write
     *   rejected           → throw InvalidArgumentException before any DB write
     *   correction_required → throw InvalidArgumentException before any DB write
     *   any other status   → throw InvalidArgumentException before any DB write
     *
     * This method MUST be called inside an active outer database transaction
     * with the row already locked by bootstrapAndLock().
     *
     * This method does NOT:
     *   - append Cost Ledger entries
     *   - mutate OutboxMessage records
     *   - mark Outbox messages as delivered or failed
     *   - trigger consumer orchestration
     */
    public function persistAllowedResultingState(
        CostAvcoState $lockedRow,
        CostLedgerPostingPlan $plan
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::persistAllowedResultingState requires an active outer transaction.'
            );
        }

        // Strict Sequence Barrier — guard before any database write
        if ($plan->decision->status !== CostLedgerPostingDecision::STATUS_ALLOW) {
            throw new InvalidArgumentException(
                sprintf(
                    'CostAvcoStateRepository: only an allow plan may persist AVCO state. ' .
                    'Received status "%s" with reason "%s". State remains unchanged.',
                    $plan->decision->status,
                    $plan->decision->reasonCode
                )
            );
        }

        $resulting = $plan->resultingState;

        // Map resulting AvcoValuationState fields to DB columns exactly
        $wauc = $resulting->weightedAverageUnitCost?->getValue();

        $lastSeq  = null;
        $lastDate = null;

        if ($resulting->lastAppliedSequence !== null) {
            $lastSeq  = $resulting->lastAppliedSequence->ledgerSequence;
            $lastDate = $resulting->lastAppliedSequence->businessDate;
        }

        $lockedRow->on_hand_quantity                = $resulting->onHandQuantity->getValue();
        $lockedRow->carrying_value                  = $resulting->carryingValue->getValue();
        $lockedRow->weighted_average_unit_cost      = $wauc;
        $lockedRow->unresolved_provisional_quantity = $resulting->unresolvedProvisionalQuantity->getValue();
        $lockedRow->last_valuation_sequence         = $lastSeq;
        $lockedRow->last_valuation_business_date    = $lastDate;
        $lockedRow->save();
    }

    /**
     * Lock all existing CostAvcoState rows for the given property + item across
     * the specified location IDs, in deterministic location_id order.
     *
     * Returns a Collection keyed by location_id; missing scopes are absent.
     * MUST be called inside an active outer transaction.
     */
    public function findLockedByScopesOrdered(
        string $propertyId,
        string $itemId,
        array $locationIds
    ): \Illuminate\Support\Collection {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::findLockedByScopesOrdered requires an active outer transaction.'
            );
        }

        if (empty($locationIds)) {
            return collect();
        }

        return CostAvcoState::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->whereIn('location_id', $locationIds)
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('location_id');
    }

    /**
     * Create a new CostAvcoState row seeded from enrollment evidence.
     *
     * Opening values are taken verbatim from the approved scope snapshot.
     * Baseline sequence N is null — proven from bootstrapAndLock initial state:
     * last_valuation_sequence = null means no CostControl transaction has been
     * processed for this scope. No fabricated N; no auto-creation.
     *
     * MUST NOT be called if a state already exists for this scope
     * (uk_cost_avco_state_scope enforces this at DB level).
     *
     * MUST be called inside an active outer transaction.
     */
    public function createFromEnrollmentSnapshot(
        string $propertyId,
        string $locationId,
        string $itemId,
        string $valuationScope,
        string $openingQuantity,
        string $openingCarryingValue,
        ?string $weightedAverageUnitCost,
        string $enrollmentGroupId,
        string $enrollmentScopeSnapshotId
    ): CostAvcoState {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::createFromEnrollmentSnapshot requires an active outer transaction.'
            );
        }

        return CostAvcoState::create([
            'property_id'                     => $propertyId,
            'location_id'                     => $locationId,
            'item_id'                         => $itemId,
            'valuation_scope'                 => $valuationScope,
            'on_hand_quantity'                => $openingQuantity,
            'carrying_value'                  => $openingCarryingValue,
            'weighted_average_unit_cost'      => $weightedAverageUnitCost,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence'         => null,
            'last_valuation_business_date'    => null,
            'enrollment_group_id'             => $enrollmentGroupId,
            'enrollment_scope_snapshot_id'    => $enrollmentScopeSnapshotId,
        ]);
    }

    /**
     * Lock an existing seeded CostAvcoState for property/location/item.
     * Does not bootstrap or insert any row.
     */
    public function lockExistingSeededStateForScope(
        string $propertyId,
        string $locationId,
        string $itemId
    ): CostAvcoState {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::lockExistingSeededStateForScope requires an active outer transaction.'
            );
        }

        $valuationScope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";

        $state = CostAvcoState::where('property_id', $propertyId)
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if ($state === null) {
            throw new RuntimeException(
                sprintf('CostAvcoState row missing for scope "%s".', $valuationScope)
            );
        }

        if ($state->valuation_scope !== $valuationScope) {
            throw new RuntimeException(
                sprintf('Scope mismatch: expected "%s", got "%s".', $valuationScope, $state->valuation_scope)
            );
        }

        if (empty($state->enrollment_group_id) || empty($state->enrollment_scope_snapshot_id)) {
            throw new RuntimeException(
                sprintf('Seed provenance missing for CostAvcoState on scope "%s".', $valuationScope)
            );
        }

        return $state;
    }

    /**
     * Persist an approved transition plan into the locked CostAvcoState.
     */
    public function persistPlannedControlledTransition(
        CostAvcoState $lockedState,
        \Modules\Finance\CostControl\ValueObjects\ControlledValuationStateTransitionPlan $plan
    ): CostAvcoState {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::persistPlannedControlledTransition requires an active outer transaction.'
            );
        }

        $expectedScope = "property:{$lockedState->property_id}:location:{$lockedState->location_id}:item:{$lockedState->item_id}";
        if ($lockedState->valuation_scope !== $plan->valuationScope || $lockedState->valuation_scope !== $expectedScope) {
            throw new InvalidArgumentException('Valuation scope mismatch before persisting transition.');
        }

        $lockedState->on_hand_quantity           = $plan->quantityAfter->getValue();
        $lockedState->carrying_value             = $plan->carryingValueAfter->getValue();
        $lockedState->weighted_average_unit_cost = $plan->weightedAverageUnitCostAfter?->getValue();
        $lockedState->last_valuation_sequence    = $plan->lastAppliedValuationSequenceAfter->ledgerSequence;
        $lockedState->last_valuation_business_date = $plan->lastAppliedValuationSequenceAfter->businessDate;
        $lockedState->save();

        return $lockedState;
    }

    /**
     * Persist an approved adjustment transition plan into the locked CostAvcoState.
     */
    public function persistPlannedAdjustmentTransition(
        CostAvcoState $lockedState,
        \Modules\Finance\CostControl\ValueObjects\ControlledAdjustmentValuationPlan $plan
    ): CostAvcoState {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::persistPlannedAdjustmentTransition requires an active outer transaction.'
            );
        }

        $expectedScope = "property:{$lockedState->property_id}:location:{$lockedState->location_id}:item:{$lockedState->item_id}";
        if ($lockedState->valuation_scope !== $plan->valuationScope || $lockedState->valuation_scope !== $expectedScope) {
            throw new InvalidArgumentException('Valuation scope mismatch before persisting transition.');
        }

        $lockedState->on_hand_quantity           = $plan->quantityAfter->getValue();
        $lockedState->carrying_value             = $plan->carryingValueAfter->getValue();
        $lockedState->weighted_average_unit_cost = $plan->weightedAverageUnitCostAfter?->getValue();
        $lockedState->last_valuation_sequence    = $plan->lastAppliedValuationSequenceAfter->ledgerSequence;
        $lockedState->last_valuation_business_date = $plan->lastAppliedValuationSequenceAfter->businessDate;
        $lockedState->save();

        return $lockedState;
    }

    /**
     * Lock exactly two existing seeded CostAvcoState rows in location_id ASC order,
     * then return them mapped as [sourceState, destState].
     */
    public function lockExistingSeededStatePair(
        string $propertyId,
        string $itemId,
        string $sourceLocationId,
        string $destinationLocationId
    ): array {
        if (trim($propertyId) === '') {
            throw new InvalidArgumentException('propertyId cannot be blank.');
        }
        if (trim($itemId) === '') {
            throw new InvalidArgumentException('itemId cannot be blank.');
        }
        if (trim($sourceLocationId) === '') {
            throw new InvalidArgumentException('sourceLocationId cannot be blank.');
        }
        if (trim($destinationLocationId) === '') {
            throw new InvalidArgumentException('destinationLocationId cannot be blank.');
        }
        if ($sourceLocationId === $destinationLocationId) {
            throw new InvalidArgumentException('Source and destination locations cannot be the same.');
        }

        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::lockExistingSeededStatePair requires an active outer transaction.'
            );
        }

        // Query exactly both rows with one query in location_id ASC order using lockForUpdate()
        $states = CostAvcoState::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->whereIn('location_id', [$sourceLocationId, $destinationLocationId])
            ->orderBy('location_id', 'asc')
            ->lockForUpdate()
            ->get();

        if ($states->count() !== 2) {
            throw new RuntimeException('Failed to lock exactly two existing seeded CostAvcoState rows.');
        }

        foreach ($states as $state) {
            $expectedScope = "property:{$propertyId}:location:{$state->location_id}:item:{$itemId}";
            if ($state->valuation_scope !== $expectedScope) {
                throw new RuntimeException(
                    sprintf('Scope mismatch: expected "%s", got "%s".', $expectedScope, $state->valuation_scope)
                );
            }
            if (empty($state->enrollment_group_id) || empty($state->enrollment_scope_snapshot_id)) {
                throw new RuntimeException(
                    sprintf('Seed provenance missing for CostAvcoState on scope "%s".', $state->valuation_scope)
                );
            }
        }

        $mapped = $states->keyBy('location_id');
        $sourceState = $mapped->get($sourceLocationId);
        $destState = $mapped->get($destinationLocationId);

        if ($sourceState === null || $destState === null) {
            throw new RuntimeException('Failed to map locked states by source/destination location.');
        }

        return [$sourceState, $destState];
    }

    /**
     * Persist both transition states from a paired transfer plan.
     */
    public function persistPairedTransferTransition(
        CostAvcoState $sourceState,
        CostAvcoState $destState,
        \Modules\Finance\CostControl\ValueObjects\ControlledTransferValuationPlan $plan
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::persistPairedTransferTransition requires an active outer transaction.'
            );
        }

        if ($sourceState->valuation_scope !== $plan->sourceValuationScope) {
            throw new InvalidArgumentException('Source valuation scope mismatch before persisting transition.');
        }

        if ($destState->valuation_scope !== $plan->destinationValuationScope) {
            throw new InvalidArgumentException('Destination valuation scope mismatch before persisting transition.');
        }

        // Provenance verification
        if (empty($sourceState->enrollment_group_id) || empty($sourceState->enrollment_scope_snapshot_id) ||
            empty($destState->enrollment_group_id) || empty($destState->enrollment_scope_snapshot_id)) {
            throw new RuntimeException('Seed provenance missing before persisting transition.');
        }

        $sourceState->on_hand_quantity                = $plan->sourceQuantityAfter->getValue();
        $sourceState->carrying_value                  = $plan->sourceCarryingValueAfter->getValue();
        $sourceState->weighted_average_unit_cost      = $plan->sourceWeightedAverageUnitCostAfter?->getValue();
        $sourceState->last_valuation_sequence         = $plan->sourceLastAppliedValuationSequenceAfter->ledgerSequence;
        $sourceState->last_valuation_business_date    = $plan->sourceLastAppliedValuationSequenceAfter->businessDate;
        $sourceState->save();

        $destState->on_hand_quantity                = $plan->destinationQuantityAfter->getValue();
        $destState->carrying_value                  = $plan->destinationCarryingValueAfter->getValue();
        $destState->weighted_average_unit_cost      = $plan->destinationWeightedAverageUnitCostAfter?->getValue();
        $destState->last_valuation_sequence         = $plan->destinationLastAppliedValuationSequenceAfter->ledgerSequence;
        $destState->last_valuation_business_date    = $plan->destinationLastAppliedValuationSequenceAfter->businessDate;
        $destState->save();
    }

    /**
     * Lock all requested unique transfer scopes across property, item, and locations,
     * sorted canonically in ORDER BY property_id, item_id, location_id ASC.
     * Returns a map keyed by scope identity: property:{property_id}:location:{location_id}:item:{item_id}
     */
    public function lockExistingSeededStateSetForTransferScopes(
        string $propertyId,
        array $requestedScopes
    ): array {
        if (trim($propertyId) === '') {
            throw new InvalidArgumentException('propertyId cannot be blank.');
        }

        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'CostAvcoStateRepository::lockExistingSeededStateSetForTransferScopes requires an active outer transaction.'
            );
        }

        $uniqueScopes = [];

        foreach ($requestedScopes as $reqScope) {
            $itemId = $reqScope['itemId'] ?? null;
            $locationId = $reqScope['locationId'] ?? null;

            if (empty($itemId) || empty($locationId)) {
                throw new InvalidArgumentException('Scope item ID and location ID must not be blank.');
            }

            $key = "{$itemId}:{$locationId}";
            $uniqueScopes[$key] = [
                'itemId' => $itemId,
                'locationId' => $locationId
            ];
        }

        // Fetch and lock the scopes matching property and exact requested (item_id, location_id) pairs
        // ordered by property_id ASC, item_id ASC, location_id ASC
        $states = CostAvcoState::where('property_id', $propertyId)
            ->where(function ($query) use ($uniqueScopes) {
                foreach ($uniqueScopes as $scope) {
                    $query->orWhere(function ($sub) use ($scope) {
                        $sub->where('item_id', $scope['itemId'])
                            ->where('location_id', $scope['locationId']);
                    });
                }
            })
            ->orderBy('property_id', 'asc')
            ->orderBy('item_id', 'asc')
            ->orderBy('location_id', 'asc')
            ->lockForUpdate()
            ->get();

        // Validate that we retrieved exactly the states for each unique scope requested
        $expectedCount = count($uniqueScopes);
        $matchedStates = [];

        foreach ($states as $state) {
            $key = "{$state->item_id}:{$state->location_id}";
            if (isset($uniqueScopes[$key])) {
                $expectedScope = "property:{$propertyId}:location:{$state->location_id}:item:{$state->item_id}";
                if ($state->valuation_scope !== $expectedScope) {
                    throw new RuntimeException(
                        sprintf('Scope mismatch: expected "%s", got "%s".', $expectedScope, $state->valuation_scope)
                    );
                }
                if (empty($state->enrollment_group_id) || empty($state->enrollment_scope_snapshot_id)) {
                    throw new RuntimeException(
                        sprintf('Seed provenance missing for CostAvcoState on scope "%s".', $state->valuation_scope)
                    );
                }
                $matchedStates[$key] = $state;
            }
        }

        if (count($matchedStates) !== $expectedCount) {
            throw new RuntimeException('Failed to retrieve all unique seeded transfer scopes.');
        }

        // Map by canonical key: property:{property_id}:location:{location_id}:item:{item_id}
        $map = [];
        foreach ($matchedStates as $state) {
            $canonicalKey = "property:{$propertyId}:location:{$state->location_id}:item:{$state->item_id}";
            $map[$canonicalKey] = $state;
        }

        return $map;
    }
}
