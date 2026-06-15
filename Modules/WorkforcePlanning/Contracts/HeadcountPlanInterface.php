<?php

namespace Modules\WorkforcePlanning\Contracts;

interface HeadcountPlanInterface
{
    public function getPlanId(): string;
    public function getPropertyId(): string;
    public function getDepartmentId(): string;
    public function getTargetHeadcount(): int;
}
