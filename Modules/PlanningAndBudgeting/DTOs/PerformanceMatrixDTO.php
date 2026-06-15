<?php

namespace Modules\PlanningAndBudgeting\DTOs;

class PerformanceMatrixDTO
{
    public ?string $companyId = null;
    public ?string $propertyId = null;
    public ?string $departmentId = null;
    public int $periodNumber;

    /** @var VarianceDTO[] */
    public array $variances = [];

    public function __construct(int $periodNumber)
    {
        $this->periodNumber = $periodNumber;
    }

    public function setCompanyContext(string $companyId): self
    {
        $this->companyId = $companyId;
        return $this;
    }

    public function setPropertyContext(string $propertyId): self
    {
        $this->propertyId = $propertyId;
        return $this;
    }

    public function setDepartmentContext(string $departmentId): self
    {
        $this->departmentId = $departmentId;
        return $this;
    }

    public function addVariance(VarianceDTO $variance): self
    {
        $this->variances[] = $variance;
        return $this;
    }
}
