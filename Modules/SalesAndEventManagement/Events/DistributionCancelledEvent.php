<?php

namespace Modules\SalesAndEventManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\SalesAndEventManagement\Models\BEODistribution;

class DistributionCancelledEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BEODistribution $distribution,
        public readonly string $oldStatus,
        public readonly ?string $performedBy = null,
    ) {}
}
