<?php

namespace Modules\Finance\CostControl\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;

class CostAuthorityEnrollmentPreflightRepository
{
    public function findGroup(string $groupId): ?CostAuthorityEnrollmentGroup
    {
        return CostAuthorityEnrollmentGroup::find($groupId);
    }

    /** @return Collection<int, CostAuthorityEnrollmentScopeSnapshot> */
    public function findSnapshots(string $groupId): Collection
    {
        return CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $groupId)->get();
    }

    /**
     * Returns location_id values with positive physical_quantity for the given
     * property + item that are NOT in the approved snapshot location set.
     *
     * @param  string[]  $approvedLocationIds
     * @return string[]
     */
    public function findPositiveStockLocationsNotInSnapshots(
        string $propertyId,
        string $itemId,
        array  $approvedLocationIds
    ): array {
        $query = DB::table('inventory_stocks')
            ->where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->where('physical_quantity', '>', 0);

        if (count($approvedLocationIds) > 0) {
            $query->whereNotIn('location_id', $approvedLocationIds);
        }

        return $query->pluck('location_id')->all();
    }

    /**
     * Returns true if any pending or failed OutboxMessage exists whose
     * source_inventory_transaction_id references an InventoryTransaction that
     * falls within the approved snapshot location + canonical scope set.
     *
     * @param  array<string, string>  $locationScopeMap  location_id => canonical_scope
     */
    public function hasPendingOrFailedOutboxForApprovedScopes(
        string $propertyId,
        string $itemId,
        array  $locationScopeMap
    ): bool {
        if (count($locationScopeMap) === 0) {
            return false;
        }

        $locationIds     = array_keys($locationScopeMap);
        $canonicalScopes = array_values($locationScopeMap);
        $pendingFailed   = [OutboxStatusEnum::Pending->value, OutboxStatusEnum::Failed->value];

        return DB::table('outbox_messages')
            ->join(
                'inventory_transactions',
                'outbox_messages.source_inventory_transaction_id',
                '=',
                'inventory_transactions.id'
            )
            ->where('inventory_transactions.property_id', $propertyId)
            ->where('inventory_transactions.item_id', $itemId)
            ->whereIn('inventory_transactions.location_id', $locationIds)
            ->whereIn('inventory_transactions.valuation_scope', $canonicalScopes)
            ->whereIn('outbox_messages.status', $pendingFailed)
            ->exists();
    }

    /**
     * Returns true if the PropertyBusinessDate for the given property and date is Open.
     */
    public function isBusinessDateOpen(string $propertyId, string $businessDate): bool
    {
        return DB::table('property_business_dates')
            ->where('property_id', $propertyId)
            ->where('business_date', $businessDate)
            ->where('status', PropertyBusinessDateStatusEnum::Open->value)
            ->exists();
    }

    /**
     * Returns true if the FinancialPeriod with the given ID is Open or Reopened.
     */
    public function isFinancialPeriodOpenOrReopened(string $financialPeriodId): bool
    {
        return DB::table('gl_financial_periods')
            ->where('id', $financialPeriodId)
            ->whereIn('status', [
                FinancialPeriodStatusEnum::Open->value,
                FinancialPeriodStatusEnum::Reopened->value,
            ])
            ->exists();
    }

    /**
     * Returns true if any JournalCandidate in an unresolved status is linked
     * (via source_id) to an InventoryTransaction within the approved snapshot
     * location + canonical scope set.
     *
     * @param  array<string, string>  $locationScopeMap     location_id => canonical_scope
     * @param  string[]               $unresolvedStatuses
     */
    public function hasUnresolvedJournalCandidateForApprovedScopes(
        string $propertyId,
        string $itemId,
        array  $locationScopeMap,
        array  $unresolvedStatuses
    ): bool {
        if (count($locationScopeMap) === 0 || count($unresolvedStatuses) === 0) {
            return false;
        }

        $locationIds     = array_keys($locationScopeMap);
        $canonicalScopes = array_values($locationScopeMap);

        return DB::table('journal_candidates')
            ->join(
                'inventory_transactions',
                'journal_candidates.source_id',
                '=',
                'inventory_transactions.id'
            )
            ->where('journal_candidates.property_id', $propertyId)
            ->where('inventory_transactions.property_id', $propertyId)
            ->where('inventory_transactions.item_id', $itemId)
            ->whereIn('inventory_transactions.location_id', $locationIds)
            ->whereIn('inventory_transactions.valuation_scope', $canonicalScopes)
            ->whereIn('journal_candidates.status', $unresolvedStatuses)
            ->exists();
    }
}
