<?php

namespace Modules\Operations\Housekeeping\Enums;

enum HousekeepingRoomReadinessTransitionTypeEnum: string
{
    case StartCleaning = 'START_CLEANING';
    case SubmitInspection = 'SUBMIT_INSPECTION';
    case ReleaseReady = 'RELEASE_READY';

    public function label(): string
    {
        return match ($this) {
            self::StartCleaning => 'Start Cleaning',
            self::SubmitInspection => 'Submit Inspection',
            self::ReleaseReady => 'Release Ready',
        };
    }
}
