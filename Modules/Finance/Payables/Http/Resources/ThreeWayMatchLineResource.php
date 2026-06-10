<?php

namespace Modules\Finance\Payables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThreeWayMatchLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_invoice_line_id' => $this->vendor_invoice_line_id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'goods_receipt_line_id' => $this->goods_receipt_line_id,
            'inventory_item_id' => $this->inventory_item_id,
            'po_quantity' => (float) $this->po_quantity,
            'po_price' => (float) $this->po_price,
            'grn_quantity' => (float) $this->grn_quantity,
            'invoice_quantity' => (float) $this->invoice_quantity,
            'invoice_price' => (float) $this->invoice_price,
            'quantity_variance' => (float) $this->quantity_variance,
            'price_variance' => (float) $this->price_variance,
            'amount_variance' => (float) $this->amount_variance,
        ];
    }
}
