<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use RuntimeException;

class CostAuthorityEnrollmentBaselineSeedService
{
    public function __construct(
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly CostAvcoStateRepository           $avcoStateRepository,
    ) {}

    /**
     * Seed CostAvcoState rows from an APPROVED enrollment group's scope snapshots.
     *
     * Does not change enrollment group status.
     * Does not activate CostControl authority.
     * Does not create GL, Inventory, Outbox, or Cost Ledger data.
     *
     * @return array<string>  CostAvcoState IDs in deterministic location_id order.
     */
    public function seedApprovedGroup(string $enrollmentGroupId, string $actorId): array
    {
        if (trim($actorId) === '') {
            throw new InvalidArgumentException(
                'CostAuthorityEnrollmentBaselineSeedService: actorId is required.'
            );
        }

        return DB::transaction(function () use ($enrollmentGroupId) {
            // Step 1: Lock the enrollment group.
            $group = $this->enrollmentRepository->findForUpdate($enrollmentGroupId);

            // Step 2: Require status = APPROVED.
            if ($group->status !== CostAuthorityEnrollmentStatusEnum::Approved) {
                throw new RuntimeException(
                    "CostAuthorityEnrollmentBaselineSeedService: group must be APPROVED. " .
                    "Current status: '{$group->status->value}'."
                );
            }

            // Step 3: Load and lock all scope snapshots in deterministic location_id order.
            $snapshots = $this->enrollmentRepository->findSnapshotsLockedByLocationOrder($enrollmentGroupId);

            if ($snapshots->isEmpty()) {
                throw new RuntimeException(
                    "CostAuthorityEnrollmentBaselineSeedService: no snapshots found for " .
                    "enrollment group '{$enrollmentGroupId}'."
                );
            }

            // Step 4: Validate every snapshot.
            foreach ($snapshots as $snapshot) {
                // Defensive: snapshot must belong to this group.
                if ($snapshot->enrollment_group_id !== $group->id) {
                    throw new RuntimeException(
                        "CostAuthorityEnrollmentBaselineSeedService: snapshot '{$snapshot->id}' " .
                        "does not belong to group '{$group->id}'."
                    );
                }

                // Canonical valuation scope: property:{property_id}:location:{location_id}:item:{item_id}
                $expected = "property:{$group->property_id}:location:{$snapshot->location_id}:item:{$group->item_id}";
                if ($snapshot->valuation_scope !== $expected) {
                    throw new RuntimeException(
                        "CostAuthorityEnrollmentBaselineSeedService: non-canonical valuation scope on " .
                        "snapshot '{$snapshot->id}'. Expected='{$expected}' " .
                        "Actual='{$snapshot->valuation_scope}'."
                    );
                }

                // Non-negative opening values (DB constraints also enforce this).
                if (bccomp((string) $snapshot->opening_quantity, '0', 4) < 0) {
                    throw new RuntimeException(
                        "CostAuthorityEnrollmentBaselineSeedService: snapshot '{$snapshot->id}' " .
                        "has negative opening_quantity."
                    );
                }

                if (bccomp((string) $snapshot->opening_carrying_value, '0', 4) < 0) {
                    throw new RuntimeException(
                        "CostAuthorityEnrollmentBaselineSeedService: snapshot '{$snapshot->id}' " .
                        "has negative opening_carrying_value."
                    );
                }
            }

            // Validate consistent business_date and financial_period_id across all snapshots.
            $uniqueDates   = $snapshots->pluck('business_date')->map(fn ($d) => (string) $d)->unique();
            $uniquePeriods = $snapshots->pluck('financial_period_id')->unique();

            if ($uniqueDates->count() > 1 || $uniquePeriods->count() > 1) {
                throw new RuntimeException(
                    "CostAuthorityEnrollmentBaselineSeedService: inconsistent snapshot context in " .
                    "group '{$group->id}'. business_dates=[{$uniqueDates->implode(',')}] " .
                    "financial_period_ids=[{$uniquePeriods->implode(',')}]."
                );
            }

            // Step 5: Baseline sequence N = null.
            // Proven from CostAvcoStateRepository::bootstrapAndLock: the authoritative initial
            // state has last_valuation_sequence = null, last_valuation_business_date = null.
            // No fabricated N. No auto-created allocator. No assumption of zero.

            // Step 6: Lock existing CostAvcoState rows in the same deterministic location_id order.
            $locationIds    = $snapshots->pluck('location_id')->all();
            $existingStates = $this->avcoStateRepository->findLockedByScopesOrdered(
                $group->property_id,
                $group->item_id,
                $locationIds
            );

            $seededIds = [];

            // Steps 7–8: Per snapshot — seed or return existing idempotent state.
            foreach ($snapshots as $snapshot) {
                $existing = $existingStates->get($snapshot->location_id);

                if ($existing !== null) {
                    // Idempotent path: this exact snapshot already seeded this state.
                    if ($existing->enrollment_scope_snapshot_id === $snapshot->id) {
                        $seededIds[] = $existing->id;
                        continue;
                    }

                    // Conflicting state: different snapshot provenance or bootstrapped by consumer.
                    // Never overwrite.
                    throw new RuntimeException(
                        "CostAuthorityEnrollmentBaselineSeedService: CostAvcoState already exists for " .
                        "property={$group->property_id} location={$snapshot->location_id} " .
                        "item={$group->item_id} with different or absent enrollment provenance. " .
                        "Cannot overwrite existing state."
                    );
                }

                // Derive WAUC using BC math — no float arithmetic; no rounding of approved evidence.
                $quantity      = (string) $snapshot->opening_quantity;
                $carryingValue = (string) $snapshot->opening_carrying_value;

                $wauc = bccomp($quantity, '0', 4) > 0
                    ? bcdiv($carryingValue, $quantity, 4)
                    : null;

                $state = $this->avcoStateRepository->createFromEnrollmentSnapshot(
                    propertyId:                $group->property_id,
                    locationId:                $snapshot->location_id,
                    itemId:                    $group->item_id,
                    valuationScope:            $snapshot->valuation_scope,
                    openingQuantity:           $quantity,
                    openingCarryingValue:      $carryingValue,
                    weightedAverageUnitCost:   $wauc,
                    enrollmentGroupId:         $group->id,
                    enrollmentScopeSnapshotId: $snapshot->id,
                );

                $seededIds[] = $state->id;
            }

            // Step 9: Return seeded state IDs. Group remains APPROVED — not changed in this slice.
            return $seededIds;
        });
    }
}
