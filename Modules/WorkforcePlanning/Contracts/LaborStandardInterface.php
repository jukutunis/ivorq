<?php

namespace Modules\WorkforcePlanning\Contracts;

interface LaborStandardInterface
{
    public function getStandardId(): string;
    public function getPropertyId(): string;
    public function getMetricType(): string;
    public function getTargetRatio(): float;
}
