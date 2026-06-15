<?php

namespace Modules\SalesAndEventManagement\Services;

use Exception;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\Account;
use Modules\SalesAndEventManagement\Models\LostBusiness;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;

class OpportunityGovernanceGuard
{
    /**
     * Enforce Company/Property Isolation for Opportunity
     */
    public function validateAccess(Opportunity $opportunity, string $userCompanyId, string $userPropertyId): void
    {
        if ($opportunity->company_id !== $userCompanyId || $opportunity->property_id !== $userPropertyId) {
            throw new Exception("GovernanceException: Cross-property or cross-company opportunity access is forbidden.");
        }
    }

    /**
     * Enforce Company Isolation for Account
     */
    public function validateAccountAccess(Account $account, string $userCompanyId): void
    {
        if ($account->company_id !== $userCompanyId) {
            throw new Exception("GovernanceException: Cross-company account access is forbidden.");
        }
    }

    /**
     * Process Opportunity Status Transition to LOST
     */
    public function transitionToLost(
        Opportunity $opportunity, 
        string $lostReason, 
        string $lostCompetitor, 
        string $lostDate, 
        ?float $lostPrice = null, 
        ?string $lostVenue = null,
        ?string $userId = null
    ): void {
        if ($opportunity->status === OpportunityStatusEnum::Lost) {
            throw new Exception("GovernanceException: Opportunity is already LOST.");
        }

        if (empty($lostReason) || empty($lostCompetitor) || empty($lostDate)) {
            throw new Exception("GovernanceException: lost_reason, lost_competitor, and lost_date are mandatory when marking an opportunity as LOST.");
        }

        $opportunity->update([
            'status' => OpportunityStatusEnum::Lost,
            'updated_by' => $userId,
        ]);

        LostBusiness::create([
            'opportunity_id' => $opportunity->id,
            'lost_reason' => $lostReason,
            'lost_competitor' => $lostCompetitor,
            'lost_date' => $lostDate,
            'lost_price' => $lostPrice,
            'lost_venue' => $lostVenue,
            'created_by' => $userId,
        ]);
    }
}
