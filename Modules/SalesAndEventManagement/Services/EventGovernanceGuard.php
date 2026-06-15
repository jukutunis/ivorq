<?php

namespace Modules\SalesAndEventManagement\Services;

use Exception;
use Modules\SalesAndEventManagement\Models\Event;
use Modules\SalesAndEventManagement\Models\EventFunction;

class EventGovernanceGuard
{
    /**
     * Validate Calendar Readiness for Event
     */
    public function validateEventCalendarReadiness(Event $event): void
    {
        if (!$event->start_datetime || !$event->end_datetime || !$event->setup_start || !$event->breakdown_end) {
            throw new Exception("GovernanceException: Event is missing calendar readiness fields (start/end/setup/breakdown datetimes).");
        }

        if ($event->start_datetime > $event->end_datetime) {
            throw new Exception("GovernanceException: Event start_datetime cannot be after end_datetime.");
        }

        if ($event->setup_start > $event->start_datetime) {
            throw new Exception("GovernanceException: Event setup_start cannot be after start_datetime.");
        }

        if ($event->end_datetime > $event->breakdown_end) {
            throw new Exception("GovernanceException: Event end_datetime cannot be after breakdown_end.");
        }
    }

    /**
     * Validate Calendar Readiness for EventFunction
     */
    public function validateFunctionCalendarReadiness(EventFunction $function): void
    {
        if (!$function->start_datetime || !$function->end_datetime || !$function->setup_start || !$function->breakdown_end) {
            throw new Exception("GovernanceException: Function is missing calendar readiness fields (start/end/setup/breakdown datetimes).");
        }

        if ($function->start_datetime > $function->end_datetime) {
            throw new Exception("GovernanceException: Function start_datetime cannot be after end_datetime.");
        }
    }
}
