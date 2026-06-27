<?php

namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentPreflightFindingCode;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentPreflightRepository;
use Modules\Finance\CostControl\ValueObjects\CostAuthorityEnrollmentPreflightFinding;
use Modules\Finance\CostControl\ValueObjects\CostAuthorityEnrollmentPreflightResult;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use RuntimeException;

class CostAuthorityEnrollmentPreflightService
{
    /** Statuses that represent an unresolved accounting obligation. */
    private const UNRESOLVED_JOURNAL_CANDIDATE_STATUSES = [
        JournalCandidateStatusEnum::DRAFT,
        JournalCandidateStatusEnum::PENDING_REVIEW,
        JournalCandidateStatusEnum::APPROVED,
        JournalCandidateStatusEnum::POSTING_FAILED,
        JournalCandidateStatusEnum::CONFIGURATION_ERROR,
    ];

    public function __construct(
        private readonly CostAuthorityEnrollmentPreflightRepository $repository,
    ) {}

    public function evaluate(string $groupId): CostAuthorityEnrollmentPreflightResult
    {
        $factual       = [];
        $prerequisites = [];

        // A. Load group as read-only data.
        $group = $this->repository->findGroup($groupId);

        if ($group === null) {
            throw new RuntimeException(
                "CostAuthorityEnrollmentPreflightService: enrollment group not found for id='{$groupId}'."
            );
        }

        // B. Group must be approved status.
        if ($group->status !== CostAuthorityEnrollmentStatusEnum::Approved) {
            $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                CostAuthorityEnrollmentPreflightFindingCode::GROUP_NOT_APPROVED,
                "status='{$group->status->value}'"
            );
        }

        // A. Load snapshots as read-only data.
        $snapshots = $this->repository->findSnapshots($groupId);

        // C. No snapshots at all.
        if ($snapshots->isEmpty()) {
            $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                CostAuthorityEnrollmentPreflightFindingCode::GROUP_HAS_NO_SNAPSHOTS
            );

            $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
                CostAuthorityEnrollmentPreflightFindingCode::BUSINESS_LOCATION_COMPLETENESS_REQUIRES_RECONCILIATION
            );
            $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
                CostAuthorityEnrollmentPreflightFindingCode::BASELINE_ALIGNMENT_NOT_EVALUATED
            );
            $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
                CostAuthorityEnrollmentPreflightFindingCode::GL_AUTHORITY_ROUTING_NOT_EVALUATED
            );
            $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
                CostAuthorityEnrollmentPreflightFindingCode::PERIOD_BOUNDARY_NOT_EVALUATED
            );

            return new CostAuthorityEnrollmentPreflightResult($groupId, $factual, $prerequisites);
        }

        // D. Recompute and validate canonical scope for every snapshot.
        $locationScopeMap     = []; // location_id => canonical_scope (valid scopes only)
        $canonicalScopeFailed = false;

        foreach ($snapshots as $snapshot) {
            $expected = "property:{$group->property_id}:location:{$snapshot->location_id}:item:{$group->item_id}";

            if ($snapshot->valuation_scope !== $expected) {
                $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                    CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_SCOPE_NOT_CANONICAL,
                    "location_id='{$snapshot->location_id}' stored='{$snapshot->valuation_scope}' expected='{$expected}'"
                );
                $canonicalScopeFailed = true;
            } else {
                $locationScopeMap[$snapshot->location_id] = $expected;
            }
        }

        // E. Require one internally consistent group context across all snapshots.
        $contextConsistent = true;

        if ($snapshots->count() > 1) {
            $businessDates      = $snapshots->pluck('business_date')->map(fn ($d) => (string) $d)->unique();
            $financialPeriodIds = $snapshots->pluck('financial_period_id')->unique();
            $currencyCodes      = $snapshots->pluck('currency_code')->unique();

            if ($businessDates->count() > 1 || $financialPeriodIds->count() > 1 || $currencyCodes->count() > 1) {
                $factual[]         = new CostAuthorityEnrollmentPreflightFinding(
                    CostAuthorityEnrollmentPreflightFindingCode::SNAPSHOT_CONTEXT_INCONSISTENT,
                    'business_dates=' . $businessDates->implode(',') .
                    ' financial_period_ids=' . $financialPeriodIds->implode(',') .
                    ' currency_codes=' . $currencyCodes->implode(',')
                );
                $contextConsistent = false;
            }
        }

        // F. Read InventoryStock to identify positive-stock locations absent from approved snapshots.
        $approvedLocationIds = array_keys($locationScopeMap);

        if (count($approvedLocationIds) > 0) {
            $uncovered = $this->repository->findPositiveStockLocationsNotInSnapshots(
                $group->property_id,
                $group->item_id,
                $approvedLocationIds
            );

            foreach ($uncovered as $locationId) {
                $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                    CostAuthorityEnrollmentPreflightFindingCode::POSITIVE_STOCK_LOCATION_NOT_COVERED,
                    "location_id='{$locationId}'"
                );
            }
        }

        // G. Always record as unresolved activation prerequisite.
        $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
            CostAuthorityEnrollmentPreflightFindingCode::BUSINESS_LOCATION_COMPLETENESS_REQUIRES_RECONCILIATION
        );

        // H. Conservatively block pending or failed source-linked OutboxMessages.
        if (count($locationScopeMap) > 0) {
            if ($this->repository->hasPendingOrFailedOutboxForApprovedScopes(
                $group->property_id,
                $group->item_id,
                $locationScopeMap
            )) {
                $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                    CostAuthorityEnrollmentPreflightFindingCode::PENDING_OR_FAILED_SOURCE_OUTBOX_MESSAGE
                );
            }
        }

        // I. Context-sensitive checks only for a single internally consistent snapshot context.
        if (!$canonicalScopeFailed && $contextConsistent && count($approvedLocationIds) > 0) {
            $referenceSnapshot = $snapshots->first();

            if (!$this->repository->isBusinessDateOpen(
                $group->property_id,
                (string) $referenceSnapshot->business_date
            )) {
                $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                    CostAuthorityEnrollmentPreflightFindingCode::PROPERTY_BUSINESS_DATE_NOT_OPEN,
                    "business_date='{$referenceSnapshot->business_date}'"
                );
            }

            if (!$this->repository->isFinancialPeriodOpenOrReopened($referenceSnapshot->financial_period_id)) {
                $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                    CostAuthorityEnrollmentPreflightFindingCode::FINANCIAL_PERIOD_NOT_OPEN,
                    "financial_period_id='{$referenceSnapshot->financial_period_id}'"
                );
            }

            $unresolvedStatuses = array_map(
                fn (JournalCandidateStatusEnum $s) => $s->value,
                self::UNRESOLVED_JOURNAL_CANDIDATE_STATUSES
            );

            if ($this->repository->hasUnresolvedJournalCandidateForApprovedScopes(
                $group->property_id,
                $group->item_id,
                $locationScopeMap,
                $unresolvedStatuses
            )) {
                $factual[] = new CostAuthorityEnrollmentPreflightFinding(
                    CostAuthorityEnrollmentPreflightFindingCode::UNRESOLVED_JOURNAL_CANDIDATE
                );
            }
        }

        // J. Always record the three unresolved activation prerequisites.
        $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
            CostAuthorityEnrollmentPreflightFindingCode::BASELINE_ALIGNMENT_NOT_EVALUATED
        );
        $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
            CostAuthorityEnrollmentPreflightFindingCode::GL_AUTHORITY_ROUTING_NOT_EVALUATED
        );
        $prerequisites[] = new CostAuthorityEnrollmentPreflightFinding(
            CostAuthorityEnrollmentPreflightFindingCode::PERIOD_BOUNDARY_NOT_EVALUATED
        );

        return new CostAuthorityEnrollmentPreflightResult($groupId, $factual, $prerequisites);
    }
}
