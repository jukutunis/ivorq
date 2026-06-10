<?php

namespace Modules\Finance\Payables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThreeWayMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'vendor_invoice_id' => $this->vendor_invoice_id,
            'purchase_order_id' => $this->purchase_order_id,
            'goods_receipt_id' => $this->goods_receipt_id,
            'status' => $this->status->value,
            'exception_code' => $this->exception_code?->value,
            'total_quantity_variance' => (float) $this->total_quantity_variance,
            'total_price_variance' => (float) $this->total_price_variance,
            'total_amount_variance' => (float) $this->total_amount_variance,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'lines' => ThreeWayMatchLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
