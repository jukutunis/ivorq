<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'property_id'      => $this->property_id,
            'cleaning_task_id' => $this->cleaning_task_id,
            'user_id'          => $this->user_id,
            'department_id'    => $this->department_id,
            'assigned_by'      => $this->assigned_by,
            'assigned_at'      => $this->assigned_at,
            'completed_at'     => $this->completed_at,
            'notes'            => $this->notes,
            'status'           => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'task' => $this->whenLoaded('task', fn() => [
                'id'        => $this->task->id,
                'task_code' => $this->task->task_code,
                'title'     => $this->task->title,
            ]),

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
