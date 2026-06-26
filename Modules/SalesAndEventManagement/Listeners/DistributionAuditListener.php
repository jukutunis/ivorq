<?php

namespace Modules\SalesAndEventManagement\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\SalesAndEventManagement\Events\DistributionAcknowledgedEvent;
use Modules\SalesAndEventManagement\Events\DistributionAcknowledgementRejectedEvent;
use Modules\SalesAndEventManagement\Events\DistributionCancelledEvent;
use Modules\SalesAndEventManagement\Events\DistributionCompletedEvent;
use Modules\SalesAndEventManagement\Events\DistributionDistributedEvent;
use Modules\SalesAndEventManagement\Events\DistributionEscalatedEvent;
use Modules\SalesAndEventManagement\Events\DistributionSupersededEvent;
use Modules\SalesAndEventManagement\Models\DistributionAuditTrail;

/**
 * DistributionAuditListener
 *
 * Subscribes to every Distribution domain event and persists an immutable
 * audit record to beo_distribution_audit_trails.
 *
 * Payload structure per Sprint 14.8.5.1 §3:
 *  - distribution_id
 *  - event_type
 *  - performed_by
 *  - old_value  (old_state snapshot)
 *  - new_value  (new_state snapshot + metadata)
 *
 * This listener is async-capable (ShouldQueue). The audit write itself is
 * synchronous by default; queue async via the standard Laravel queue config.
 */
class DistributionAuditListener implements ShouldQueue
{
    public function handleDistributed(DistributionDistributedEvent $event): void
    {
        DistributionAuditTrail::create([
            'distribution_id' => $event->distribution->id,
            'event_type'      => 'DISTRIBUTED',
            'performed_by'    => $event->distributedBy,
            'old_value'       => ['status' => 'DRAFT'],
            'new_value'       => [
                'status'               => 'DISTRIBUTED',
                'departments_notified' => $event->departmentIds,
                'distributed_at'       => now()->toISOString(),
            ],
        ]);
    }

    public function handleSuperseded(DistributionSupersededEvent $event): void
    {
        DistributionAuditTrail::create([
            'distribution_id' => $event->distribution->id,
            'event_type'      => 'SUPERSEDED',
            'performed_by'    => null,
            'old_value'       => ['status' => $event->oldStatus],
            'new_value'       => ['status' => 'SUPERSEDED'],
        ]);
    }

    public function handleCancelled(DistributionCancelledEvent $event): void
    {
        DistributionAuditTrail::create([
            'distribution_id' => $event->distribution->id,
            'event_type'      => 'CANCELLED',
            'performed_by'    => $event->performedBy,
            'old_value'       => ['status' => $event->oldStatus],
            'new_value'       => ['status' => 'CANCELLED'],
        ]);
    }

    public function handleAcknowledged(DistributionAcknowledgedEvent $event): void
    {
        DistributionAuditTrail::create([
            'distribution_id' => $event->acknowledgement->beo_distribution_id,
            'event_type'      => 'ACKNOWLEDGED',
            'performed_by'    => $event->userId,
            'old_value'       => ['status' => 'PENDING_OR_VIEWED'],
            'new_value'       => [
                'status'          => 'ACKNOWLEDGED',
                'department_id'   => $event->acknowledgement->department_id,
                'acknowledged_at' => $event->acknowledgement->acknowledged_at?->toISOString(),
            ],
        ]);
    }

    public function handleAcknowledgementRejected(DistributionAcknowledgementRejectedEvent $event): void
    {
        DistributionAuditTrail::create([
            'distribution_id' => $event->acknowledgement->beo_distribution_id,
            'event_type'      => 'ACKNOWLEDGEMENT_REJECTED',
            'performed_by'    => $event->userId,
            'old_value'       => ['status' => 'PENDING_OR_VIEWED'],
            'new_value'       => [
                'status'        => 'REJECTED',
                'department_id' => $event->acknowledgement->department_id,
                'reason'        => $event->reason,
            ],
        ]);
    }

    public function handleEscalated(DistributionEscalatedEvent $event): void
    {
        $escalation = $event->escalation;

        DistributionAuditTrail::create([
            'distribution_id' => $escalation->acknowledgement->beo_distribution_id,
            'event_type'      => 'ESCALATED',
            'performed_by'    => null, // system-driven
            'old_value'       => ['status' => 'PENDING_OR_VIEWED'],
            'new_value'       => [
                'status'            => 'ESCALATED',
                'department_id'     => $escalation->acknowledgement->department_id,
                'escalation_level'  => $escalation->escalation_level,
                'escalated_at'      => $escalation->escalated_at?->toISOString(),
            ],
        ]);
    }

    public function handleCompleted(DistributionCompletedEvent $event): void
    {
        DistributionAuditTrail::create([
            'distribution_id' => $event->distribution->id,
            'event_type'      => 'COMPLETED',
            'performed_by'    => null,
            'old_value'       => ['status' => 'FULLY_ACKNOWLEDGED'],
            'new_value'       => ['status' => 'COMPLETED'],
        ]);
    }
}
