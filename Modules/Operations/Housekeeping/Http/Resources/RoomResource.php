<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'zone_id'     => $this->zone_id,
            'room_number' => $this->room_number,
            'room_name'   => $this->room_name,
            'room_type'   => [
                'value' => $this->room_type->value,
                'label' => $this->room_type->label(),
            ],
            'floor'    => $this->floor,
            'building' => $this->building,

            'cleanliness_status' => [
                'value' => $this->cleanliness_status->value,
                'label' => $this->cleanliness_status->label(),
            ],

            // occupancy_status is nullable — PMS not yet active
            'occupancy_status' => $this->occupancy_status
                ? ['value' => $this->occupancy_status->value, 'label' => $this->occupancy_status->label()]
                : null,

            'is_active'  => $this->is_active,
            'notes'      => $this->notes,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'zone' => $this->whenLoaded('zone', fn() => [
                'id'        => $this->zone->id,
                'zone_code' => $this->zone->zone_code,
                'zone_name' => $this->zone->zone_name,
            ]),

            'status_histories' => RoomStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'inspections'      => RoomInspectionResource::collection($this->whenLoaded('inspections')),
        ];
    }
}
