<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CleaningTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'room_id'     => $this->room_id,
            'zone_id'     => $this->zone_id,
            'task_code'   => $this->task_code,
            'title'       => $this->title,
            'description' => $this->description,
            'task_type'   => [
                'value' => $this->task_type->value,
                'label' => $this->task_type->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'priority'                   => $this->priority,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'due_date'                   => $this->due_date,
            'started_at'                 => $this->started_at,
            'completed_at'               => $this->completed_at,
            'completed_by'               => $this->completed_by,

            // Computed: actual duration in minutes (null if not yet complete)
            'actual_duration_minutes' => ($this->started_at && $this->completed_at)
                ? (int) $this->started_at->diffInMinutes($this->completed_at)
                : null,

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'room' => $this->whenLoaded('room', fn() => [
                'id'          => $this->room->id,
                'room_number' => $this->room->room_number,
                'room_name'   => $this->room->room_name,
            ]),

            'zone' => $this->whenLoaded('zone', fn() => [
                'id'        => $this->zone->id,
                'zone_code' => $this->zone->zone_code,
                'zone_name' => $this->zone->zone_name,
            ]),

            'completed_by_user' => $this->whenLoaded('completedBy', fn() => $this->completedBy
                ? ['id' => $this->completedBy->id, 'name' => $this->completedBy->name]
                : null
            ),

            'assignments' => TaskAssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
