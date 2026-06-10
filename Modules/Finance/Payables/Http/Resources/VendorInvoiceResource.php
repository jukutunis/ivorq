<?php

namespace Modules\Finance\Payables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'vendor_id' => $this->vendor_id,
            'purchase_order_id' => $this->purchase_order_id,
            'goods_receipt_id' => $this->goods_receipt_id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'grand_total' => (float) $this->grand_total,
            'remarks' => $this->remarks,
            'lines' => VendorInvoiceLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
