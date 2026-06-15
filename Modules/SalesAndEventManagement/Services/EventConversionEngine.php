<?php

namespace Modules\SalesAndEventManagement\Services;

use Exception;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\Event;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;
use Modules\SalesAndEventManagement\Enums\EventStatusEnum;
use Modules\SalesAndEventManagement\Enums\EventTypeEnum;

class EventConversionEngine
{
    /**
     * Converts a WON Opportunity into an Event.
     */
    public function convertOpportunityToEvent(
        Opportunity $opportunity, 
        EventTypeEnum $eventType,
        string $userId
    ): Event {
        if ($opportunity->status !== OpportunityStatusEnum::Definite) {
            throw new Exception("ConversionException: Only DEFINITE opportunities can be converted to Events.");
        }

        // Check if Event already exists for this Opportunity
        $existingEvent = Event::where('opportunity_id', $opportunity->id)->first();
        if ($existingEvent) {
            throw new Exception("ConversionException: Opportunity has already been converted to an Event.");
        }

        $event = Event::create([
            'opportunity_id' => $opportunity->id,
            'event_name' => $opportunity->opportunity_name,
            'status' => EventStatusEnum::Tentative,
            'event_type' => $eventType,
            
            // Assume the expected event date is the start, but leave calendar times null to force proper scheduling
            'start_datetime' => $opportunity->expected_event_date ? $opportunity->expected_event_date->startOfDay() : null,
            'end_datetime' => $opportunity->expected_event_date ? $opportunity->expected_event_date->endOfDay() : null,
            'setup_start' => $opportunity->expected_event_date ? $opportunity->expected_event_date->startOfDay()->subHours(2) : null,
            'breakdown_end' => $opportunity->expected_event_date ? $opportunity->expected_event_date->endOfDay()->addHours(2) : null,
            
            'created_by' => $userId,
        ]);

        return $event;
    }
}
