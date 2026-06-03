<?php

namespace Modules\Foundation\Audit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'property_id'    => $this->property_id,
            'user_id'        => $this->user_id,
            'event'          => $this->event,
            'auditable_type' => class_basename($this->auditable_type),
            'auditable_id'   => $this->auditable_id,
            'old_values'     => $this->old_values,
            'new_values'     => $this->new_values,
            'ip_address'     => $this->ip_address,
            'url'            => $this->url,
            'tags'           => $this->tags,
            'created_at'     => $this->created_at,
        ];
    }
}
