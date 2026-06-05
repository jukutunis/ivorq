<?php

namespace Modules\Operations\PMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'property_id'    => $this->property_id,
            'reservation_id' => $this->reservation_id,
            'room_id'        => $this->room_id,
            'guest_id'       => $this->guest_id,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'check_in_at'           => $this->check_in_at,
            'expected_departure_at' => $this->expected_departure_at,
            'check_out_at'          => $this->check_out_at,

            // Computed duration in minutes (null until checked out)
            'duration_minutes' => ($this->check_in_at && $this->check_out_at)
                ? (int) $this->check_in_at->diffInMinutes($this->check_out_at)
                : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // ── Nested relations ──────────────────────────────────────────────
            'reservation' => new ReservationResource($this->whenLoaded('reservation')),

            'guest' => new GuestResource($this->whenLoaded('guest')),

            'room' => $this->whenLoaded('room', fn () => $this->room
                ? [
                    'id'          => $this->room->id,
                    'room_number' => $this->room->room_number,
                    'room_name'   => $this->room->room_name,
                    'room_type'   => [
                        'value' => $this->room->room_type->value,
                        'label' => $this->room->room_type->label(),
                    ],
                ]
                : null
            ),
        ];
    }
}
