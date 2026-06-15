<?php

namespace Modules\PlanningAndBudgeting\DTOs;

class VarianceDTO
{
    public string $categoryName;
    public float $budgetAmount;
    public float $forecastAmount;
    public float $actualAmount;

    public float $varianceToBudget;
    public float $varianceToBudgetPercent;
    
    public float $varianceToForecast;
    public float $varianceToForecastPercent;

    public ?string $varianceReason = null;
    public ?string $varianceComment = null;

    public function __construct(
        string $categoryName,
        float $budgetAmount = 0.0,
        float $forecastAmount = 0.0,
        float $actualAmount = 0.0
    ) {
        $this->categoryName = $categoryName;
        $this->budgetAmount = $budgetAmount;
        $this->forecastAmount = $forecastAmount;
        $this->actualAmount = $actualAmount;

        $this->calculateVariances();
    }

    private function calculateVariances(): void
    {
        $this->varianceToBudget = $this->actualAmount - $this->budgetAmount;
        $this->varianceToBudgetPercent = $this->budgetAmount != 0 
            ? ($this->varianceToBudget / abs($this->budgetAmount)) * 100 
            : 0;

        $this->varianceToForecast = $this->actualAmount - $this->forecastAmount;
        $this->varianceToForecastPercent = $this->forecastAmount != 0 
            ? ($this->varianceToForecast / abs($this->forecastAmount)) * 100 
            : 0;
    }
}
