<?php

namespace Modules\Operations\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // SLA deadline: created_at + promised hours (null when no SLA defined)
        $slaDueAt = $this->sla_hours
            ? $this->created_at->copy()->addHours((float) $this->sla_hours)
            : null;

        // Breached when the resolution point (completed_at or now) exceeds the deadline
        $slaBreached = $slaDueAt
            ? ($this->completed_at ?? now())->gt($slaDueAt)
            : null;

        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'work_order_number' => $this->work_order_number,
            'title'             => $this->title,
            'description'       => $this->description,

            'work_order_type' => [
                'value' => $this->work_order_type->value,
                'label' => $this->work_order_type->label(),
            ],
            'priority' => [
                'value' => $this->priority->value,
                'label' => $this->priority->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'location_type'        => $this->location_type,
            'room_id'              => $this->room_id,
            'zone_id'              => $this->zone_id,
            'location_description' => $this->location_description,
            'asset_description'    => $this->asset_description,

            'sla_hours'       => $this->sla_hours,
            'estimated_hours' => $this->estimated_hours,
            'actual_hours'    => $this->actual_hours,
            'due_date'        => $this->due_date,

            'sla_due_at'   => $slaDueAt,
            'sla_breached' => $slaBreached,

            'started_at'          => $this->started_at,
            'completed_at'        => $this->completed_at,
            'completed_by'        => $this->completed_by,
            'on_hold_reason'      => $this->on_hold_reason,
            'cancelled_at'        => $this->cancelled_at,
            'cancelled_by'        => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'approved_by'         => $this->approved_by,
            'approved_at'         => $this->approved_at,

            // Actual duration in hours — available only once both started and completed
            'actual_duration_hours' => ($this->started_at && $this->completed_at)
                ? round($this->started_at->diffInMinutes($this->completed_at) / 60, 2)
                : null,

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'room' => $this->whenLoaded('room', fn() => $this->room
                ? ['id' => $this->room->id, 'room_number' => $this->room->room_number, 'room_name' => $this->room->room_name]
                : null
            ),

            'zone' => $this->whenLoaded('zone', fn() => $this->zone
                ? ['id' => $this->zone->id, 'zone_code' => $this->zone->zone_code, 'zone_name' => $this->zone->zone_name]
                : null
            ),

            'completed_by_user' => $this->whenLoaded('completedBy', fn() => $this->completedBy
                ? ['id' => $this->completedBy->id, 'name' => $this->completedBy->name]
                : null
            ),

            'cancelled_by_user' => $this->whenLoaded('cancelledBy', fn() => $this->cancelledBy
                ? ['id' => $this->cancelledBy->id, 'name' => $this->cancelledBy->name]
                : null
            ),

            'approved_by_user' => $this->whenLoaded('approvedBy', fn() => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null
            ),

            'assignments_count' => $this->whenCounted('assignments'),
            'assignments'       => TechnicianAssignmentResource::collection($this->whenLoaded('assignments')),

            'status_histories_count' => $this->whenCounted('statusHistories'),
            'status_histories'       => WorkOrderStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
        ];
    }
}
