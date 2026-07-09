<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskDepartureClosureReadinessStatusEnum: string
{
    case ClosureReady = 'CLOSURE_READY';
    case ClosureBlocked = 'CLOSURE_BLOCKED';
    case ClosureReviewed = 'CLOSURE_REVIEWED';
}
