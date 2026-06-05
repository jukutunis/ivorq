<?php

namespace Modules\Operations\PMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RatePlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'rate_code'   => $this->rate_code,
            'rate_name'   => $this->rate_name,

            'plan_type' => [
                'value' => $this->plan_type->value,
                'label' => $this->plan_type->label(),
            ],

            'base_rate'   => (float) $this->base_rate,
            'currency'    => $this->currency,
            'is_active'   => $this->is_active,
            'description' => $this->description,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
