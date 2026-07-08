<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskDeparturePreparationEventTypeEnum: string
{
    case DepartureNoteRecorded = 'DEPARTURE_NOTE_RECORDED';
    case DepartureTimeConfirmed = 'DEPARTURE_TIME_CONFIRMED';
    case LuggageAssistanceNoted = 'LUGGAGE_ASSISTANCE_NOTED';
    case TransportationNoted = 'TRANSPORTATION_NOTED';
    case OperationalBlockerAcknowledged = 'OPERATIONAL_BLOCKER_ACKNOWLEDGED';
    case GuestMessageNoted = 'GUEST_MESSAGE_NOTED';
}
