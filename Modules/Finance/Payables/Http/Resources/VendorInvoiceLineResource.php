<?php

namespace Modules\Finance\Payables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorInvoiceLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_invoice_id' => $this->vendor_invoice_id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'goods_receipt_line_id' => $this->goods_receipt_line_id,
            'inventory_item_id' => $this->inventory_item_id,
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'line_total' => (float) $this->line_total,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
