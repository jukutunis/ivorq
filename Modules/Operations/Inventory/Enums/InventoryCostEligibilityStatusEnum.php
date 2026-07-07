<?php

namespace Modules\Operations\Inventory\Enums;

enum InventoryCostEligibilityStatusEnum: string
{
    case CostingReady = 'COSTING_READY';
    case CostingBlockedFxUnsupported = 'COSTING_BLOCKED_FX_UNSUPPORTED';
    case CostingBlockedUnvaluedMovement = 'COSTING_BLOCKED_UNVALUED_MOVEMENT';
    case CostingBlockedInsufficientCostEvidence = 'COSTING_BLOCKED_INSUFFICIENT_COST_EVIDENCE';
    case CostingBlockedInconsistentMovementEvidence = 'COSTING_BLOCKED_INCONSISTENT_MOVEMENT_EVIDENCE';
}
