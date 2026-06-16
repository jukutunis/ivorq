<?php

namespace Modules\SalesAndEventManagement\Contracts\Projections;

use Modules\SalesAndEventManagement\Models\BEOIssueLog;

interface StewardingProjection
{
    public function project(BEOIssueLog $beo): array;
}
