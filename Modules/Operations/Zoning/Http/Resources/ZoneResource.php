<?php

namespace Modules\Operations\Zoning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'zone_code'   => $this->zone_code,
            'zone_name'   => $this->zone_name,
            'zone_type'   => [
                'value' => $this->zone_type->value,
                'label' => $this->zone_type->label(),
            ],
            'description' => $this->description,
            'status'      => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'priority'    => [
                'value' => $this->priority->value,
                'label' => $this->priority->label(),
            ],
            'created_by'  => $this->created_by,
            'updated_by'  => $this->updated_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,

            'active_assignments_count' => $this->whenCounted('activeAssignments'),

            'assignments' => ZoneAssignmentResource::collection($this->whenLoaded('assignments')),
            'histories'   => ZoneHistoryResource::collection($this->whenLoaded('histories')),
        ];
    }
}
