<?php

namespace Modules\SalesAndEventManagement\Contracts\Projections;

use Modules\SalesAndEventManagement\Models\BEOIssueLog;

interface EngineeringProjection
{
    public function project(BEOIssueLog $beo): array;
}
