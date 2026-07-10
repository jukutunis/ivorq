<?php

namespace Modules\Operations\FrontDesk\Enums;

enum FrontDeskDepartureCheckoutExecutionBoundaryStatusEnum: string
{
    case ExecutionBoundaryReady = 'EXECUTION_BOUNDARY_READY';
    case ExecutionBoundaryBlocked = 'EXECUTION_BOUNDARY_BLOCKED';
    case ExecutionBoundaryReviewRequired = 'EXECUTION_BOUNDARY_REVIEW_REQUIRED';
}
