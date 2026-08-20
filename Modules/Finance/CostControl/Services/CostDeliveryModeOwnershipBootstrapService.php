<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use RuntimeException;

class CostDeliveryModeOwnershipBootstrapService
{
    public function __construct(
        private readonly CostAuthorityEnrollmentRepository $enrollmentRepository,
        private readonly CostDeliveryModeOwnershipRepository $ownershipRepository,
    ) {}

    public function bootstrap(
        string $enrollmentGroupId,
        string $actorId
    ): CostDeliveryModeOwnership {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        if (trim($enrollmentGroupId) === '' || trim($actorId) === '') {
            throw new InvalidArgumentException('Enrollment group ID and actor ID are required.');
        }

        $group = $this->enrollmentRepository->findForUpdate($enrollmentGroupId);

        if ($group->status !== CostAuthorityEnrollmentStatusEnum::Enrolled) {
            throw new RuntimeException(
                "Cost delivery ownership bootstrap requires status=ENROLLED. Current status='{$group->status->value}'."
            );
        }

        $snapshots = $this->enrollmentRepository->findSnapshotsLockedByLocationOrder($group->id);
        if ($snapshots->isEmpty()) {
            throw new RuntimeException('Cost delivery ownership bootstrap requires complete enrollment snapshots.');
        }

        $seenLocations = [];
        foreach ($snapshots as $snapshot) {
            $expectedScope = "property:{$group->property_id}:location:{$snapshot->location_id}:item:{$group->item_id}";

            if ($snapshot->enrollment_group_id !== $group->id
                || $snapshot->valuation_scope !== $expectedScope
                || trim((string) $snapshot->location_id) === ''
                || trim((string) $snapshot->currency_code) === ''
                || $snapshot->business_date === null
                || trim((string) $snapshot->financial_period_id) === ''
                || $snapshot->evidence_timestamp === null
                || isset($seenLocations[$snapshot->location_id])) {
                throw new RuntimeException('Cost delivery ownership bootstrap rejected incomplete or mismatched scope snapshots.');
            }

            $seenLocations[$snapshot->location_id] = true;
        }

        $existing = $this->ownershipRepository->findForUpdateByEnrollmentGroup($group->id);
        if ($existing !== null) {
            if (! $this->ownershipRepository->isExactInitialSynchronous($existing, $group)) {
                throw new RuntimeException('Cost delivery ownership bootstrap found mismatched existing ownership.');
            }

            return $existing;
        }

        $ownership = $this->ownershipRepository->createInitialSynchronous($group, $actorId);

        if (! $this->ownershipRepository->isExactInitialSynchronous($ownership, $group)) {
            throw new RuntimeException('Cost delivery ownership bootstrap failed exact persistence verification.');
        }

        return $ownership;
    }
}
