<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CleaningChecklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'name'        => $this->name,

            // task_type is nullable
            'task_type' => $this->task_type
                ? ['value' => $this->task_type->value, 'label' => $this->task_type->label()]
                : null,

            'description' => $this->description,
            'is_active'   => $this->is_active,
            'created_by'  => $this->created_by,
            'updated_by'  => $this->updated_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,

            'items_count' => $this->whenCounted('items'),
            'items'       => ChecklistItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
