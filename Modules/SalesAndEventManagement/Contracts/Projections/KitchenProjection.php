<?php

namespace Modules\SalesAndEventManagement\Contracts\Projections;

use Modules\SalesAndEventManagement\Models\BEOIssueLog;

interface KitchenProjection
{
    public function project(BEOIssueLog $beo): array;
}
