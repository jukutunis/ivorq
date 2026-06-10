<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_request_id' => $this->purchase_request_id,
            'inventory_item_id' => $this->inventory_item_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_id' => $this->unit_id,
            'estimated_unit_cost' => $this->estimated_unit_cost,
            'estimated_total_cost' => $this->estimated_total_cost,
            'remarks' => $this->remarks,
        ];
    }
}
