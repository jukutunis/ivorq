<?php

namespace Modules\Operations\Receiving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivingDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grn_number' => $this->grn_number,
            'vendor_delivery_no' => $this->vendor_delivery_no,
            'status' => $this->status,
            'received_at' => $this->received_at?->toIso8601String(),
            'remarks' => $this->remarks,
            'vendor_id' => $this->vendor_id,
            'purchase_order_id' => $this->purchase_order_id,
            'received_by' => $this->received_by,
            'lines' => ReceivingLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
