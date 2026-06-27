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
}
