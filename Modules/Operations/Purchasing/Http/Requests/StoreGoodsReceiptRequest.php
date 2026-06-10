<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Purchasing\Models\PurchaseOrder;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('goods-receipt.create');
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'received_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'exists:purchase_order_lines,id'],
            'lines.*.location_id' => ['required', 'exists:inventory_locations,id'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
