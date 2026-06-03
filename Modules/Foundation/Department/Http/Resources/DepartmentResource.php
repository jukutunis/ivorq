<?php

namespace Modules\Foundation\Department\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'name'        => $this->name,
            'code'        => $this->code,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'positions_count' => $this->when(isset($this->positions_count), $this->positions_count),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
