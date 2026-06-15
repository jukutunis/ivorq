<?php

namespace Modules\PlanningAndBudgeting\Contracts;

interface VarianceThresholdInterface
{
    /**
     * Determine severity level (e.g., GREEN, YELLOW, RED) based on variance percentage or absolute amount.
     * Foundation readiness only. No implementation storage.
     *
     * @param string $categoryName E.g. 'Room Revenue'
     * @param float $variancePercent
     * @param float $absoluteVariance
     * @return string
     */
    public function determineSeverity(string $categoryName, float $variancePercent, float $absoluteVariance): string;
}
