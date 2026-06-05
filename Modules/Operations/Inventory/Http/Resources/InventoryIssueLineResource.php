<?php

namespace Modules\Operations\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryIssueLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'issue_id'    => $this->issue_id,

            'item_id'     => $this->item_id,
            'location_id' => $this->location_id,

            'quantity' => (float) $this->quantity,
            'remarks'  => $this->remarks,

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
