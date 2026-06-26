<?php

namespace Modules\SalesAndEventManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;

class DistributionAcknowledgedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BEOAcknowledgement $acknowledgement,
        public readonly string $userId,
    ) {}
}
