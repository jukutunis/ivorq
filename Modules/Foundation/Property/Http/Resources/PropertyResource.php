<?php

namespace Modules\Foundation\Property\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'company_id' => $this->company_id,
            'company'    => new CompanyResource($this->whenLoaded('company')),
            'name'       => $this->name,
            'slug'       => $this->slug,
            'code'       => $this->code,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'address'    => $this->address,
            'city'       => $this->city,
            'country'    => $this->country,
            'timezone'   => $this->timezone,
            'currency'   => $this->currency,
            'logo'       => $this->logo,
            'is_active'  => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
