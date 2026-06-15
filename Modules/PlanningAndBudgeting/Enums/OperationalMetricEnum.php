<?php

namespace Modules\PlanningAndBudgeting\Enums;

enum OperationalMetricEnum: string
{
    case Covers = 'COVERS';
    case AverageCheck = 'AVERAGE_CHECK';
    case FoodCostPercent = 'FOOD_COST_PERCENT';
    case BeverageCostPercent = 'BEVERAGE_COST_PERCENT';
    case OutletRevenueTarget = 'OUTLET_REVENUE_TARGET';
}
