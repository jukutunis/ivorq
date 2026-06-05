<?php

namespace Modules\Operations\PMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'folio_number'   => $this->folio_number,
            'reservation_id' => $this->reservation_id,
            'guest_id'       => $this->guest_id,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'currency'       => $this->currency,
            'total_charges'  => (float) $this->total_charges,
            'total_payments' => (float) $this->total_payments,
            'balance'        => (float) $this->balance,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // ── Nested relations ──────────────────────────────────────────────
            'reservation' => $this->whenLoaded('reservation', fn () => $this->reservation
                ? [
                    'id'                 => $this->reservation->id,
                    'reservation_number' => $this->reservation->reservation_number,
                ]
                : null
            ),

            'guest' => new GuestResource($this->whenLoaded('guest')),

            'items_count'  => $this->whenCounted('items'),
            'items'        => FolioItemResource::collection($this->whenLoaded('items')),
            'active_items' => FolioItemResource::collection($this->whenLoaded('activeItems')),
        ];
    }
}
