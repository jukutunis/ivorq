<?php

namespace Modules\Finance\CostControl\Adapters;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Repositories\CostDeliveryCutoverRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use RuntimeException;

class InventoryCostDeliveryModeAdapter implements CostDeliveryModePort
{
    public function __construct(
        private readonly CostDeliveryModeOwnershipRepository $ownershipRepository,
        private readonly CostDeliveryCutoverRepository $cutoverRepository,
    ) {}

    public function isEnrolled(string $propertyId, string $itemId): bool
    {
        return CostAuthorityEnrollmentGroup::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->where('status', CostAuthorityEnrollmentStatusEnum::Enrolled->value)
            ->exists();
    }

    public function lockForDocumentMutation(string $propertyId, string $itemId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        $candidateGroup = CostAuthorityEnrollmentGroup::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->where('status', CostAuthorityEnrollmentStatusEnum::Enrolled->value)
            ->first();
        if ($candidateGroup === null) {
            return;
        }

        $ownership = $this->ownershipRepository->findForUpdateByEnrollmentGroup($candidateGroup->id);
        $group = CostAuthorityEnrollmentGroup::whereKey($candidateGroup->id)
            ->lockForUpdate()
            ->first();
        if ($group === null
            || $group->property_id !== $propertyId
            || $group->item_id !== $itemId
            || $group->status !== CostAuthorityEnrollmentStatusEnum::Enrolled) {
            throw new RuntimeException('ENROLLED_DELIVERY_ENROLLMENT_CHANGED');
        }
        if ($ownership === null) {
            throw new RuntimeException('ENROLLED_DELIVERY_OWNERSHIP_MISSING');
        }
        if ($ownership->property_id !== $propertyId
            || $ownership->item_id !== $itemId
            || $ownership->enrollment_group_id !== $group->id) {
            throw new RuntimeException('ENROLLED_DELIVERY_OWNERSHIP_MISMATCH');
        }
    }

    public function resolveForPosting(
        string $propertyId,
        string $itemId,
        string $locationId
    ): CostDeliveryPostingDecision {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }

        $candidateGroup = CostAuthorityEnrollmentGroup::where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->where('status', CostAuthorityEnrollmentStatusEnum::Enrolled->value)
            ->first();

        if ($candidateGroup === null) {
            return CostDeliveryPostingDecision::notEnrolled($propertyId, $itemId);
        }

        $ownership = $this->ownershipRepository->findForUpdateByEnrollmentGroup($candidateGroup->id);
        $group = CostAuthorityEnrollmentGroup::whereKey($candidateGroup->id)
            ->lockForUpdate()
            ->first();

        if ($group === null
            || $group->property_id !== $propertyId
            || $group->item_id !== $itemId
            || $group->status !== CostAuthorityEnrollmentStatusEnum::Enrolled) {
            throw new RuntimeException('ENROLLED_DELIVERY_ENROLLMENT_CHANGED');
        }

        if ($ownership === null) {
            throw new RuntimeException('ENROLLED_DELIVERY_OWNERSHIP_MISSING');
        }

        $expectedScope = "property:{$propertyId}:location:{$locationId}:item:{$itemId}";
        $scope = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $group->id)
            ->where('location_id', $locationId)
            ->where('valuation_scope', $expectedScope)
            ->first();

        if ($scope === null) {
            throw new RuntimeException('ENROLLED_DELIVERY_SCOPE_MISSING');
        }

        if ($ownership->property_id !== $propertyId
            || $ownership->item_id !== $itemId
            || $ownership->enrollment_group_id !== $group->id) {
            throw new RuntimeException('ENROLLED_DELIVERY_OWNERSHIP_MISMATCH');
        }

        return match ($ownership->delivery_mode) {
            CostDeliveryMode::Synchronous => CostDeliveryPostingDecision::synchronous(
                $propertyId,
                $itemId,
                $locationId,
                $scope->valuation_scope,
                $ownership->id,
                $ownership->ownership_version,
            ),
            CostDeliveryMode::Deferred => $this->resolveDeferred(
                $propertyId,
                $itemId,
                $locationId,
                $scope->valuation_scope,
                $group,
                $ownership,
            ),
        };
    }

    private function resolveDeferred(
        string $propertyId,
        string $itemId,
        string $locationId,
        string $valuationScope,
        CostAuthorityEnrollmentGroup $group,
        CostDeliveryModeOwnership $ownership
    ): CostDeliveryPostingDecision {
        $cutoverId = $ownership->activated_cutover_id
            ?? throw new RuntimeException('DEFERRED_DELIVERY_CUTOVER_MISSING');
        $cutover = $this->cutoverRepository->findActivatedForUpdate($ownership->id, $cutoverId);

        if ($cutover === null
            || $cutover->ownership_id !== $ownership->id
            || $cutover->property_id !== $propertyId
            || $cutover->item_id !== $itemId
            || $cutover->enrollment_group_id !== $group->id) {
            throw new RuntimeException('DEFERRED_DELIVERY_CUTOVER_MISMATCH');
        }

        $scope = $this->cutoverRepository->findScopeForUpdate(
            $cutover->id,
            $propertyId,
            $itemId,
            $locationId,
            $valuationScope,
        );

        if ($scope === null) {
            throw new RuntimeException('DEFERRED_DELIVERY_SCOPE_WATERMARK_MISSING');
        }

        return CostDeliveryPostingDecision::deferred(
            $propertyId,
            $itemId,
            $locationId,
            $valuationScope,
            $ownership->id,
            $ownership->ownership_version,
            $cutover->id,
            $scope->last_synchronously_owned_sequence,
            $scope->first_deferred_owned_sequence,
        );
    }
}
