<?php

namespace Modules\PlanningAndBudgeting\Services;

use Modules\PlanningAndBudgeting\Models\ForecastVersion;
use Modules\PlanningAndBudgeting\Models\BudgetVersion;
use Exception;

class ForecastSeedingFoundation
{
    /**
     * Foundation to seed a forecast version from a Budget Version
     */
    public function seedFromBudget(ForecastVersion $forecastVersion, BudgetVersion $budgetVersion): void
    {
        // TODO: Implement copying Budget Entries and Assumptions into Forecast Entries and Assumptions
        // Architecture only for Sprint 13.4.1
    }

    /**
     * Foundation to seed a forecast version from Actual Operational Metrics
     */
    public function seedFromActuals(ForecastVersion $forecastVersion, array $periodRange): void
    {
        // TODO: Implement importing Actuals into historical forecast periods
        // Architecture only for Sprint 13.4.1
    }

    /**
     * Foundation to seed revenue forecast from PMS Pace data
     */
    public function seedFromPmsPace(ForecastVersion $forecastVersion): void
    {
        // TODO: Implement importing Booking Pace and Reservations On Hand (ROH) from PMS
        // Architecture only for Sprint 13.4.1
    }

    /**
     * Foundation to seed revenue forecast from Revenue Management System
     */
    public function seedFromRms(ForecastVersion $forecastVersion): void
    {
        // TODO: Implement importing unconstrained demand and channel forecasts from RMS
        // Architecture only for Sprint 13.4.1
    }

    /**
     * Foundation to seed operational forecast from Sales & Event Management
     */
    public function seedFromSalesAndEvents(ForecastVersion $forecastVersion): void
    {
        // TODO: Implement importing Group Blocks and BEO financial projections
        // Architecture only for Sprint 13.4.1
    }
}
