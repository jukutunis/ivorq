<?php

namespace Modules\Operations\PMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'reservation_number' => $this->reservation_number,

            'primary_guest_id' => $this->primary_guest_id,
            'rate_plan_id'     => $this->rate_plan_id,

            'adults'   => $this->adults,
            'children' => $this->children,

            'arrival_date'   => $this->arrival_date?->toDateString(),
            'departure_date' => $this->departure_date?->toDateString(),
            'nights'         => $this->nights,

            'reservation_source' => [
                'value' => $this->reservation_source->value,
                'label' => $this->reservation_source->label(),
            ],

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'reserved_room_type' => [
                'value' => $this->reserved_room_type->value,
                'label' => $this->reserved_room_type->label(),
            ],

            'assigned_room_id' => $this->assigned_room_id,
            'remarks'          => $this->remarks,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // ── Nested relations ──────────────────────────────────────────────
            'primary_guest' => new GuestResource($this->whenLoaded('primaryGuest')),

            'guests' => GuestResource::collection($this->whenLoaded('guests')),

            'rate_plan' => new RatePlanResource($this->whenLoaded('ratePlan')),

            'assigned_room' => $this->whenLoaded('assignedRoom', fn () => $this->assignedRoom
                ? [
                    'id'          => $this->assignedRoom->id,
                    'room_number' => $this->assignedRoom->room_number,
                    'room_name'   => $this->assignedRoom->room_name,
                    'room_type'   => [
                        'value' => $this->assignedRoom->room_type->value,
                        'label' => $this->assignedRoom->room_type->label(),
                    ],
                    'cleanliness_status' => [
                        'value' => $this->assignedRoom->cleanliness_status->value,
                        'label' => $this->assignedRoom->cleanliness_status->label(),
                    ],
                    'occupancy_status' => $this->assignedRoom->occupancy_status
                        ? [
                            'value' => $this->assignedRoom->occupancy_status->value,
                            'label' => $this->assignedRoom->occupancy_status->label(),
                        ]
                        : null,
                ]
                : null
            ),

            'stays_count' => $this->whenCounted('stays'),
            'stays'       => StayResource::collection($this->whenLoaded('stays')),

            'folios_count' => $this->whenCounted('folios'),
            'folios'       => FolioResource::collection($this->whenLoaded('folios')),
        ];
    }
}
