<?php

namespace Modules\Operations\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngineeringChecklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'title'       => $this->title,
            'description' => $this->description,
            'checklist_type' => [
                'value' => $this->checklist_type->value,
                'label' => $this->checklist_type->label(),
            ],
            'is_active'  => $this->is_active,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'items_count' => $this->whenCounted('items'),
            'items'       => EngineeringChecklistItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
