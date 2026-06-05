<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Append-only ledger — no created_at/updated_at, no soft delete.
 * Exposes unit_cost and total_value for costing visibility.
 */
class InventoryStockCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,

            'item_id'     => $this->item_id,
            'location_id' => $this->location_id,

            'movement_type' => [
                'value' => $this->movement_type->value,
                'label' => $this->movement_type->label(),
            ],

            'quantity_before' => (float) $this->quantity_before,
            'quantity_change'  => (float) $this->quantity_change,
            'quantity_after'   => (float) $this->quantity_after,

            // Costing fields
            'unit_cost'   => $this->unit_cost  !== null ? (float) $this->unit_cost  : null,
            'total_value' => $this->total_value !== null ? (float) $this->total_value : null,

            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'notes'          => $this->notes,

            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),

            // No created_at / updated_at — this table has $timestamps = false

            'item' => $this->whenLoaded('item', fn() => $this->item
                ? ['id' => $this->item->id, 'item_code' => $this->item->item_code, 'name' => $this->item->name]
                : null
            ),

            'location' => $this->whenLoaded('location', fn() => $this->location
                ? ['id' => $this->location->id, 'location_code' => $this->location->location_code, 'name' => $this->location->name]
                : null
            ),

            'posted_by_user' => $this->whenLoaded('postedBy', fn() => $this->postedBy
                ? ['id' => $this->postedBy->id, 'name' => $this->postedBy->name]
                : null
            ),
        ];
    }
}
