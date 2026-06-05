<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'transfer_id' => $this->transfer_id,

            'item_id' => $this->item_id,

            'quantity_requested' => (float) $this->quantity_requested,
            'notes'              => $this->notes,

            'item' => $this->whenLoaded('item', fn() => $this->item
                ? ['id' => $this->item->id, 'item_code' => $this->item->item_code, 'name' => $this->item->name]
                : null
            ),
        ];
    }
}
