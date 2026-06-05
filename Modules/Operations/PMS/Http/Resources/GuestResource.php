<?php

namespace Modules\Operations\PMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'guest_code'  => $this->guest_code,
            'full_name'   => $this->full_name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'nationality' => $this->nationality,
            'id_type'     => $this->id_type,
            'id_number'   => $this->id_number,

            'guest_type' => [
                'value' => $this->guest_type->value,
                'label' => $this->guest_type->label(),
            ],

            'vip_level' => $this->vip_level,
            'notes'     => $this->notes,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'reservations' => ReservationResource::collection($this->whenLoaded('reservations')),
            'stays'        => StayResource::collection($this->whenLoaded('stays')),
            'folios'       => FolioResource::collection($this->whenLoaded('folios')),
        ];
    }
}
