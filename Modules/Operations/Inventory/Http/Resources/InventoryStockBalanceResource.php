<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryStockBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'item_id'     => $this->item_id,
            'location_id' => $this->location_id,

            'quantity' => (float) $this->quantity,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            'last_movement_at' => $this->last_movement_at?->toIso8601String(),

            // created_by, updated_by, deleted_at intentionally omitted

            'item' => $this->whenLoaded('item', fn() => $this->item
                ? ['id' => $this->item->id, 'item_code' => $this->item->item_code, 'name' => $this->item->name]
                : null
            ),

            'location' => $this->whenLoaded('location', fn() => $this->location
                ? ['id' => $this->location->id, 'location_code' => $this->location->location_code, 'name' => $this->location->name]
                : null
            ),
        ];
    }
}
