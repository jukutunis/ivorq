<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'location_code' => $this->location_code,
            'name'          => $this->name,
            'description'   => $this->description,
            'location_type' => [
                'value' => $this->location_type->value,
                'label' => $this->location_type->label(),
            ],
            'is_active' => (bool) $this->is_active,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // created_by, updated_by, deleted_at intentionally omitted
        ];
    }
}
