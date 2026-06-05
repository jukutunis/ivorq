<?php

namespace Modules\Operations\Engineering\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Engineering\Models\WorkOrder;

class WorkOrderOnHold
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkOrder $workOrder,
        public readonly ?string   $reason
    ) {}
}
