<?php

namespace Modules\Operations\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TechnicianAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'property_id'   => $this->property_id,
            'work_order_id' => $this->work_order_id,
            'user_id'       => $this->user_id,
            'department_id' => $this->department_id,
            'role' => [
                'value' => $this->role->value,
                'label' => $this->role->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'assigned_by'  => $this->assigned_by,
            'assigned_at'  => $this->assigned_at,
            'started_at'   => $this->started_at,
            'completed_at' => $this->completed_at,
            'hours_worked' => $this->hours_worked,
            'remarks'      => $this->remarks,
            'created_by'   => $this->created_by,
            'updated_by'   => $this->updated_by,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,

            'work_order' => $this->whenLoaded('workOrder', fn() => $this->workOrder
                ? [
                    'id'                => $this->workOrder->id,
                    'work_order_number' => $this->workOrder->work_order_number,
                    'title'             => $this->workOrder->title,
                  ]
                : null
            ),

            'user' => $this->whenLoaded('user', fn() => $this->user
                ? ['id' => $this->user->id, 'name' => $this->user->name]
                : null
            ),

            'department' => $this->whenLoaded('department', fn() => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
                : null
            ),

            'assigned_by_user' => $this->whenLoaded('assignedBy', fn() => $this->assignedBy
                ? ['id' => $this->assignedBy->id, 'name' => $this->assignedBy->name]
                : null
            ),
        ];
    }
}
