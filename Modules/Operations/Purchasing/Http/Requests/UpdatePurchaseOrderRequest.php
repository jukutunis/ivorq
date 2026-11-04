<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'po_no' => ['prohibited'],
            'vendor_id' => ['prohibited'],
            'purchase_request_id' => ['prohibited'],
            'status' => ['prohibited'],
            'currency_code' => ['prohibited'],
            'exchange_rate' => ['prohibited'],
            'issue_date' => ['prohibited'],
            'expected_delivery_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required_with:lines', 'string', 'exists:purchase_order_lines,id'],
            'lines.*.ordered_quantity' => ['sometimes', 'numeric', 'min:0.001'],
            'lines.*.unit_cost' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
