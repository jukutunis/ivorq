<?php

namespace Modules\SalesAndEventManagement\Services;

use Modules\SalesAndEventManagement\Models\Opportunity;

class OpportunityConversionFoundation
{
    /**
     * Converts a WON Opportunity into an Event.
     * Foundation readiness only. Do NOT create Event model yet.
     */
    public function convertToEvent(Opportunity $opportunity): void
    {
        // TODO: Implement logic to create an Event block from an Opportunity
        // Architecture only for Sprint 14.2
    }
}
