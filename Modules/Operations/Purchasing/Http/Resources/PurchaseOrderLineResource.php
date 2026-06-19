<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_request_line_id' => $this->purchase_request_line_id,
            'inventory_item_id' => $this->inventory_item_id,
            'description' => $this->description,
            'ordered_quantity' => $this->ordered_quantity,
            'quantity_received' => $this->quantity_received,
            'unit_cost' => $this->unit_cost,
            'line_total' => $this->line_total,
            'inventory_item' => $this->whenLoaded('inventoryItem'),
            'unit' => $this->whenLoaded('unit'),
        ];
    }
}
