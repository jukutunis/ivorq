<?php

namespace Modules\SalesAndEventManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\SalesAndEventManagement\Models\BEODistribution;

class DistributionDistributedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BEODistribution $distribution,
        public readonly string $distributedBy,
        public readonly array $departmentIds,
    ) {}
}
