<?php

namespace Modules\SalesAndEventManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\SalesAndEventManagement\Models\BEODistribution;

class DistributionCompletedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BEODistribution $distribution,
    ) {}
}
