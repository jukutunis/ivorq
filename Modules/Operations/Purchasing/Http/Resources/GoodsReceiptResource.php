<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grn_no' => $this->grn_no,
            'purchase_order_id' => $this->purchase_order_id,
            'vendor_id' => $this->vendor_id,
            'received_date' => $this->received_date?->format('Y-m-d'),
            'status' => $this->status,
            'remarks' => $this->remarks,
            'lines' => GoodsReceiptLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
