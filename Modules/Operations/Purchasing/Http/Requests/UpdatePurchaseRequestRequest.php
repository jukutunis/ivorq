<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_no' => ['sometimes', 'string', 'max:50'],
            'department_id' => ['sometimes', 'string', 'size:26'],
            'requester_id' => ['sometimes', 'string', 'size:26'],
            'required_date' => ['sometimes', 'date'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['nullable', 'string', 'size:26'],
            'lines.*.inventory_item_id' => ['nullable', 'string', 'size:26'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['required_with:lines', 'string', 'size:26'],
            'lines.*.estimated_unit_cost' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string'],
        ];
    }
}
