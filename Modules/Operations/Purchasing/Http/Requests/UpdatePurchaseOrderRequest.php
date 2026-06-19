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
            'remarks' => ['nullable', 'string', 'max:1000'],
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required_with:lines', 'string', 'exists:purchase_order_lines,id'],
            'lines.*.ordered_quantity' => ['sometimes', 'numeric', 'min:0.001'],
            'lines.*.unit_cost' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
