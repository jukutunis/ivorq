<?php

namespace Modules\Operations\WorkOrder\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\WorkOrder\Models\WorkOrder;
use Modules\Operations\WorkOrder\Models\WorkOrderApproval;

class WorkOrderApprovalGranted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WorkOrder $workOrder,
        public WorkOrderApproval $approval
    ) {}
}
