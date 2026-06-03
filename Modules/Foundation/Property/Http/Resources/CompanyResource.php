<?php

namespace Modules\Foundation\Property\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'address'    => $this->address,
            'logo'       => $this->logo,
            'is_active'  => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
