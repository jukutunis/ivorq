<?php

namespace Modules\Operations\Zoning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Foundation\Department\Http\Resources\DepartmentResource;
use Modules\Foundation\User\Http\Resources\UserResource;

class ZoneAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'zone_id'       => $this->zone_id,
            'property_id'   => $this->property_id,
            'user_id'       => $this->user_id,
            'department_id' => $this->department_id,
            'start_date'    => $this->start_date?->toDateString(),
            'end_date'      => $this->end_date?->toDateString(),
            'status'        => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,

            'zone'       => new ZoneResource($this->whenLoaded('zone')),
            'user'       => new UserResource($this->whenLoaded('user')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
        ];
    }
}
