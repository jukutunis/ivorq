<?php

namespace Modules\Operations\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreventiveMaintenanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'pm_code'     => $this->pm_code,
            'title'       => $this->title,
            'description' => $this->description,
            'frequency' => [
                'value' => $this->frequency->value,
                'label' => $this->frequency->label(),
            ],
            'frequency_days' => $this->frequency_days,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'room_id'           => $this->room_id,
            'zone_id'           => $this->zone_id,
            'asset_description' => $this->asset_description,
            'estimated_hours'   => $this->estimated_hours,
            'department_id'     => $this->department_id,
            'last_run_at'       => $this->last_run_at,
            'next_due_at'       => $this->next_due_at,
            'created_by'        => $this->created_by,
            'updated_by'        => $this->updated_by,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,

            'room' => $this->whenLoaded('room', fn() => $this->room
                ? ['id' => $this->room->id, 'room_number' => $this->room->room_number, 'room_name' => $this->room->room_name]
                : null
            ),

            'zone' => $this->whenLoaded('zone', fn() => $this->zone
                ? ['id' => $this->zone->id, 'zone_code' => $this->zone->zone_code, 'zone_name' => $this->zone->zone_name]
                : null
            ),

            'department' => $this->whenLoaded('department', fn() => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
                : null
            ),

            'tasks_count' => $this->whenCounted('tasks'),
            'tasks'       => PreventiveMaintenanceTaskResource::collection($this->whenLoaded('tasks')),
        ];
    }
}
