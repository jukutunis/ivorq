<?php

namespace Modules\Operations\Engineering\Listeners;

use Modules\Operations\Engineering\Events\WorkOrderAssigned;
use Modules\Operations\Engineering\Events\WorkOrderCancelled;
use Modules\Operations\Engineering\Events\WorkOrderCompleted;
use Modules\Operations\Engineering\Events\WorkOrderCreated;
use Modules\Operations\Engineering\Events\WorkOrderOnHold;
use Modules\Operations\Engineering\Events\WorkOrderStarted;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Models\WorkOrderStatusHistory;

class RecordWorkOrderHistory
{
    public function handle(
        WorkOrderCreated|WorkOrderAssigned|WorkOrderStarted|WorkOrderOnHold|WorkOrderCompleted|WorkOrderCancelled $event
    ): void {
        match (true) {
            $event instanceof WorkOrderCreated   => $this->onCreated($event),
            $event instanceof WorkOrderAssigned  => $this->onAssigned($event),
            $event instanceof WorkOrderStarted   => $this->onStarted($event),
            $event instanceof WorkOrderOnHold    => $this->onOnHold($event),
            $event instanceof WorkOrderCompleted => $this->onCompleted($event),
            $event instanceof WorkOrderCancelled => $this->onCancelled($event),
        };
    }

    private function onCreated(WorkOrderCreated $event): void
    {
        WorkOrderStatusHistory::record([
            'property_id'    => $event->workOrder->property_id,
            'work_order_id'  => $event->workOrder->id,
            'from_status'    => null,
            'to_status'      => $event->workOrder->status->value,
            'remarks'        => null,
            'changed_by'     => auth()->id(),
            'changed_at'     => now(),
            'created_by'     => auth()->id(),
        ]);
    }

    private function onAssigned(WorkOrderAssigned $event): void
    {
        WorkOrderStatusHistory::record([
            'property_id'    => $event->workOrder->property_id,
            'work_order_id'  => $event->workOrder->id,
            'from_status'    => $this->lastStatus($event->workOrder),
            'to_status'      => $event->workOrder->status->value,
            'remarks'        => null,
            'changed_by'     => auth()->id(),
            'changed_at'     => now(),
            'created_by'     => auth()->id(),
        ]);
    }

    private function onStarted(WorkOrderStarted $event): void
    {
        WorkOrderStatusHistory::record([
            'property_id'    => $event->workOrder->property_id,
            'work_order_id'  => $event->workOrder->id,
            'from_status'    => $this->lastStatus($event->workOrder),
            'to_status'      => $event->workOrder->status->value,
            'remarks'        => null,
            'changed_by'     => auth()->id(),
            'changed_at'     => now(),
            'created_by'     => auth()->id(),
        ]);
    }

    private function onOnHold(WorkOrderOnHold $event): void
    {
        WorkOrderStatusHistory::record([
            'property_id'    => $event->workOrder->property_id,
            'work_order_id'  => $event->workOrder->id,
            'from_status'    => $this->lastStatus($event->workOrder),
            'to_status'      => $event->workOrder->status->value,
            'remarks'        => $event->reason,
            'changed_by'     => auth()->id(),
            'changed_at'     => now(),
            'created_by'     => auth()->id(),
        ]);
    }

    private function onCompleted(WorkOrderCompleted $event): void
    {
        WorkOrderStatusHistory::record([
            'property_id'    => $event->workOrder->property_id,
            'work_order_id'  => $event->workOrder->id,
            'from_status'    => $this->lastStatus($event->workOrder),
            'to_status'      => $event->workOrder->status->value,
            'remarks'        => null,
            'changed_by'     => auth()->id(),
            'changed_at'     => now(),
            'created_by'     => auth()->id(),
        ]);
    }

    private function onCancelled(WorkOrderCancelled $event): void
    {
        WorkOrderStatusHistory::record([
            'property_id'    => $event->workOrder->property_id,
            'work_order_id'  => $event->workOrder->id,
            'from_status'    => $this->lastStatus($event->workOrder),
            'to_status'      => $event->workOrder->status->value,
            'remarks'        => $event->reason,
            'changed_by'     => auth()->id(),
            'changed_at'     => now(),
            'created_by'     => auth()->id(),
        ]);
    }

    /**
     * Returns the to_status value from the most recent history record for this
     * work order. Used as the from_status for the new record being inserted.
     * Returns null if no prior history exists (defensive; should not occur after
     * WorkOrderCreated fires).
     */
    private function lastStatus(WorkOrder $workOrder): ?string
    {
        return WorkOrderStatusHistory::where('work_order_id', $workOrder->id)
            ->latest('changed_at')
            ->value('to_status');
    }
}
