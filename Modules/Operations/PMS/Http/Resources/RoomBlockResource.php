<?php

namespace Modules\Operations\PMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'room_id'     => $this->room_id,

            'block_type' => [
                'value' => $this->block_type->value,
                'label' => $this->block_type->label(),
            ],

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'reason' => $this->reason
                ? ['value' => $this->reason->value, 'label' => $this->reason->label()]
                : null,

            'notes'       => $this->notes,
            'start_at'    => $this->start_at,
            'end_at'      => $this->end_at,
            'released_at' => $this->released_at,
            'released_by' => $this->released_by,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // ── Nested relations ──────────────────────────────────────────────
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

            'released_by_user' => $this->whenLoaded('releasedBy', fn () => $this->releasedBy
                ? ['id' => $this->releasedBy->id, 'name' => $this->releasedBy->name]
                : null
            ),
        ];
    }
}
