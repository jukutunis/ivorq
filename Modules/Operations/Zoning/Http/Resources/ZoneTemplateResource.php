<?php

namespace Modules\Operations\Zoning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'property_id'      => $this->property_id,
            'template_name'    => $this->template_name,
            'zone_type'        => [
                'value' => $this->zone_type->value,
                'label' => $this->zone_type->label(),
            ],
            'default_priority' => [
                'value' => $this->default_priority->value,
                'label' => $this->default_priority->label(),
            ],
            'description'      => $this->description,
            'is_active'        => $this->is_active,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
