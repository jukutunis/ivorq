<?php

namespace Modules\Operations\Receiving\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \Modules\Operations\Receiving\Models\ReceivingDocument::class);
    }

    public function rules(): array
    {
        return [
            'vendor_id' => 'required|string|exists:vendors,id',
            'purchase_order_id' => 'nullable|string|exists:purchase_orders,id',
            'vendor_delivery_no' => 'nullable|string|max:255',
            'received_at' => 'nullable|date',
            'remarks' => 'nullable|string',
            
            'lines' => 'required|array|min:1',
            'lines.*.purchase_order_line_id' => 'nullable|string|exists:purchase_order_lines,id',
            'lines.*.inventory_item_id' => 'nullable|string',
            'lines.*.inventory_unit_id' => 'nullable|string',
            'lines.*.destination_location_id' => 'nullable|string',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.received_quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_cost' => 'required|numeric|min:0',
            'lines.*.line_total' => 'required|numeric|min:0',
            'lines.*.serial_number' => 'nullable|string|max:255',
            'lines.*.imei' => 'nullable|string|max:255',
            'lines.*.mac_address' => 'nullable|string|max:255',
            'lines.*.lot_number' => 'nullable|string|max:255',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.manufacture_date' => 'nullable|date',
        ];
    }
}
