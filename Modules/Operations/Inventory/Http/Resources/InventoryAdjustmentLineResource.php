<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdjustmentLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'property_id'   => $this->property_id,
            'adjustment_id' => $this->adjustment_id,

            'item_id' => $this->item_id,

            'quantity_system'   => (float) $this->quantity_system,
            'quantity_actual'   => (float) $this->quantity_actual,
            'quantity_variance' => (float) $this->quantity_variance,

            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'notes'     => $this->notes,

            'item' => $this->whenLoaded('item', fn() => $this->item
                ? ['id' => $this->item->id, 'item_code' => $this->item->item_code, 'name' => $this->item->name]
                : null
            ),
        ];
    }
}
