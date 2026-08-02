<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'property_id'      => $this->property_id,
            'room_id'          => $this->room_id,
            'cleaning_task_id' => $this->cleaning_task_id,
            'inspector_id'     => $this->supervisor_id,
            'inspection_type'  => [
                'value' => $this->inspection_type->value,
                'label' => $this->inspection_type->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            // inspection_severity is nullable
            'inspection_severity' => $this->inspection_severity
                ? ['value' => $this->inspection_severity->value, 'label' => $this->inspection_severity->label()]
                : null,

            'remarks'      => $this->remarks,
            'inspected_at' => $this->inspected_at,
            'created_by'   => $this->created_by,
            'updated_by'   => $this->updated_by,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,

            'room' => $this->whenLoaded('room', fn() => [
                'id'          => $this->room->id,
                'room_number' => $this->room->room_number,
                'room_name'   => $this->room->room_name,
            ]),

            'task' => $this->whenLoaded('task', fn() => $this->task
                ? ['id' => $this->task->id, 'task_code' => $this->task->task_code, 'title' => $this->task->title]
                : null
            ),

            'inspector' => $this->whenLoaded('inspector', fn() => $this->inspector
                ? ['id' => $this->inspector->id, 'name' => $this->inspector->name]
                : null
            ),

            'photos' => InspectionPhotoResource::collection($this->whenLoaded('photos')),
        ];
    }
}
