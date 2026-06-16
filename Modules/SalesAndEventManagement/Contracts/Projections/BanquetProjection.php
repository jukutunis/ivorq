<?php

namespace Modules\SalesAndEventManagement\Contracts\Projections;

use Modules\SalesAndEventManagement\Models\BEOIssueLog;

interface BanquetProjection
{
    public function project(BEOIssueLog $beo): array;
}
