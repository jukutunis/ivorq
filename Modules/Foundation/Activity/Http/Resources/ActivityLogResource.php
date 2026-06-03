<?php

namespace Modules\Foundation\Activity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'property_id'  => $this->property_id,
            'user_id'      => $this->user_id,
            'description'  => $this->description,
            'subject_type' => $this->subject_type ? class_basename($this->subject_type) : null,
            'subject_id'   => $this->subject_id,
            'causer_type'  => $this->causer_type ? class_basename($this->causer_type) : null,
            'causer_id'    => $this->causer_id,
            'properties'   => $this->properties,
            'created_at'   => $this->created_at,
        ];
    }
}
