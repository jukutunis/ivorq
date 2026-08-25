<?php

namespace Modules\Finance\CostControl\Enums;

enum CostDeliveryDispositionClass: string
{
    case SynchronouslySatisfiedHistory = 'SYNCHRONOUSLY_SATISFIED_HISTORY';
    case UnenrolledOrNonCostControlEligibleHistory = 'UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY';
    case DeferredOwnedAfterCutover = 'DEFERRED_OWNED_AFTER_CUTOVER';
}
