<?php

namespace Modules\Operations\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'po_no' => $this->po_no,
            'vendor_id' => $this->vendor_id,
            'purchase_request_id' => $this->purchase_request_id,
            'issue_date' => $this->issue_date,
            'expected_delivery_date' => $this->expected_delivery_date,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'received_total' => $this->received_total,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'vendor' => $this->whenLoaded('vendor'),
            'purchase_request' => clone $this->whenLoaded('purchaseRequest'),
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
