<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'property_id'  => $this->property_id,
            'room_id'      => $this->room_id,
            'status_field' => $this->status_field,
            'from_status'  => $this->from_status,
            'to_status'    => $this->to_status,
            'action'       => $this->action,
            'remarks'      => $this->remarks,
            'performed_by' => $this->performed_by,
            'created_at'   => $this->created_at,

            'performer' => $this->whenLoaded('performer', fn() => $this->performer
                ? ['id' => $this->performer->id, 'name' => $this->performer->name]
                : null
            ),
        ];
    }
}
