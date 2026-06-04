<?php

namespace Modules\Operations\Zoning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Foundation\User\Http\Resources\UserResource;

class ZoneHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'zone_id'     => $this->zone_id,
            'action'      => $this->action,
            'remarks'     => $this->remarks,
            'created_at'  => $this->created_at,

            // zone_id is nullable — zone may have been deleted (SET NULL)
            'zone'      => $this->whenLoaded('zone', fn() => $this->zone
                ? new ZoneResource($this->zone)
                : null
            ),

            // performed_by is nullable — user may have been deleted (SET NULL)
            'performer' => $this->whenLoaded('performer', fn() => $this->performer
                ? new UserResource($this->performer)
                : null
            ),
        ];
    }
}
