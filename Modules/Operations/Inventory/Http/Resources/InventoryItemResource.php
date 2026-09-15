<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'inventory_type' => $this->inventory_type,
            'criticality' => $this->criticality,
            'is_batch_tracked' => (bool) $this->is_batch_tracked,
            'is_expiry_tracked' => (bool) $this->is_expiry_tracked,
            'weighted_average_cost' => (float) $this->weighted_average_cost,
            'is_active' => (bool) $this->is_active,
            'reorder_point' => (float) $this->reorder_point,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'category' => $this->whenLoaded('category', fn () => $this->category
                ? ['id' => $this->category->id, 'name' => $this->category->name]
                : null),
            'stock_balances_count' => $this->whenCounted('stockBalances'),
            'stock_balances' => InventoryStockBalanceResource::collection($this->whenLoaded('stockBalances')),
        ];
    }
}
