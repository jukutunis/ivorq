<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Repositories\CostAvcoStateRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use RuntimeException;

class CostAuthorityEnrollmentActivationService
{
    public function __construct(
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly CostAvcoStateRepository $avcoStateRepository,
        private readonly CostDeliveryModeOwnershipRepository $ownershipRepository,
    ) {}

    /**
     * Atomically activate an approved enrollment and its initial ownership.
     *
     * Retries after a committed activation fail closed. This service never
     * seeds AVCO baseline state and never repairs or reuses ownership evidence.
     */
    public function activate(string $enrollmentGroupId, string $actorId): CostDeliveryModeOwnership
    {
        if (trim($enrollmentGroupId) === '' || trim($actorId) === '') {
            throw new InvalidArgumentException(
                'CostAuthorityEnrollmentActivationService: enrollmentGroupId and actorId are required.'
            );
        }

        return DB::transaction(function () use ($enrollmentGroupId, $actorId): CostDeliveryModeOwnership {
            $group = $this->enrollmentRepository->findForUpdate($enrollmentGroupId);

            if ($group->status !== CostAuthorityEnrollmentStatusEnum::Approved) {
                throw new RuntimeException(
                    'CostAuthorityEnrollmentActivationService: group must be APPROVED. '.
                    "Current status: '{$group->status->value}'."
                );
            }

            $snapshots = $this->enrollmentRepository
                ->findSnapshotsLockedByLocationOrder($group->id);

            if ($snapshots->isEmpty()) {
                throw new RuntimeException(
                    "CostAuthorityEnrollmentActivationService: no snapshots found for enrollment group '{$group->id}'."
                );
            }

            $seenLocations = [];
            $businessDates = [];
            $financialPeriods = [];

            foreach ($snapshots as $snapshot) {
                $expectedScope = "property:{$group->property_id}:location:{$snapshot->location_id}:item:{$group->item_id}";

                if ($snapshot->enrollment_group_id !== $group->id
                    || trim((string) $snapshot->location_id) === ''
                    || $snapshot->valuation_scope !== $expectedScope
                    || trim((string) $snapshot->currency_code) === ''
                    || $snapshot->business_date === null
                    || trim((string) $snapshot->financial_period_id) === ''
                    || $snapshot->evidence_timestamp === null
                    || isset($seenLocations[$snapshot->location_id])) {
                    throw new RuntimeException(
                        'CostAuthorityEnrollmentActivationService: incomplete or non-canonical enrollment snapshots.'
                    );
                }

                $seenLocations[$snapshot->location_id] = true;
                $businessDates[(string) $snapshot->business_date] = true;
                $financialPeriods[$snapshot->financial_period_id] = true;
            }

            if (count($businessDates) !== 1 || count($financialPeriods) !== 1) {
                throw new RuntimeException(
                    'CostAuthorityEnrollmentActivationService: inconsistent enrollment snapshot context.'
                );
            }

            $states = $this->avcoStateRepository->findLockedByScopesOrdered(
                $group->property_id,
                $group->item_id,
                $snapshots->pluck('location_id')->all(),
            );

            if ($states->count() !== $snapshots->count()) {
                throw new RuntimeException(
                    'CostAuthorityEnrollmentActivationService: complete seeded CostAvcoState baseline is required.'
                );
            }

            foreach ($snapshots as $snapshot) {
                $state = $states->get($snapshot->location_id);
                $quantity = (string) $snapshot->opening_quantity;
                $carryingValue = (string) $snapshot->opening_carrying_value;
                $expectedWauc = bccomp($quantity, '0', 4) > 0
                    ? bcdiv($carryingValue, $quantity, 4)
                    : null;

                if ($state === null
                    || $state->valuation_scope !== $snapshot->valuation_scope
                    || $state->enrollment_group_id !== $group->id
                    || $state->enrollment_scope_snapshot_id !== $snapshot->id
                    || bccomp((string) $state->on_hand_quantity, $quantity, 4) !== 0
                    || bccomp((string) $state->carrying_value, $carryingValue, 4) !== 0
                    || bccomp((string) $state->unresolved_provisional_quantity, '0', 4) !== 0
                    || ($expectedWauc === null
                        ? $state->weighted_average_unit_cost !== null
                        : $state->weighted_average_unit_cost === null
                            || bccomp((string) $state->weighted_average_unit_cost, $expectedWauc, 4) !== 0)
                    || $state->last_valuation_sequence !== null
                    || $state->last_valuation_business_date !== null) {
                    throw new RuntimeException(
                        "CostAuthorityEnrollmentActivationService: seeded CostAvcoState baseline mismatch for snapshot '{$snapshot->id}'."
                    );
                }
            }

            if ($this->ownershipRepository->findForUpdateByEnrollmentGroup($group->id) !== null
                || $this->ownershipRepository->findForUpdateByPropertyItem(
                    $group->property_id,
                    $group->item_id,
                ) !== null) {
                throw new RuntimeException(
                    'CostAuthorityEnrollmentActivationService: conflicting delivery ownership already exists.'
                );
            }

            $enrolled = $this->enrollmentRepository->enrollApproved($group->id, now());
            $ownership = $this->ownershipRepository->createInitialSynchronous($enrolled, $actorId);

            if (! $this->ownershipRepository->isExactInitialSynchronous($ownership, $enrolled)) {
                throw new RuntimeException(
                    'CostAuthorityEnrollmentActivationService: initial ownership persistence verification failed.'
                );
            }

            return $ownership;
        });
    }
}
