<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'item_code'   => $this->item_code,
            'name'        => $this->name,
            'description' => $this->description,
            'sku'         => $this->sku,
            'barcode'     => $this->barcode,

            'category_id' => $this->category_id,
            'unit_id'     => $this->unit_id,

            'min_stock'        => $this->min_stock        !== null ? (float) $this->min_stock        : null,
            'max_stock'        => $this->max_stock        !== null ? (float) $this->max_stock        : null,
            'reorder_point'    => $this->reorder_point    !== null ? (float) $this->reorder_point    : null,
            'reorder_quantity' => $this->reorder_quantity !== null ? (float) $this->reorder_quantity : null,

            // average_cost is WAC-managed — exposed read-only so consumers can display cost
            'average_cost' => (float) $this->average_cost,

            'is_active' => (bool) $this->is_active,
            'notes'     => $this->notes,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // created_by, updated_by, deleted_at intentionally omitted

            'category' => $this->whenLoaded('category', fn() => $this->category
                ? ['id' => $this->category->id, 'category_code' => $this->category->category_code, 'name' => $this->category->name]
                : null
            ),

            'unit' => $this->whenLoaded('unit', fn() => $this->unit
                ? ['id' => $this->unit->id, 'unit_code' => $this->unit->unit_code, 'name' => $this->unit->name, 'abbreviation' => $this->unit->abbreviation]
                : null
            ),

            'stock_balances_count' => $this->whenCounted('stockBalances'),
            'stock_balances'       => InventoryStockBalanceResource::collection($this->whenLoaded('stockBalances')),
        ];
    }
}
