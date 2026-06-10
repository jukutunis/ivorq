<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_no' => ['required', 'string', 'max:50'],
            'department_id' => ['required', 'string', 'size:26'],
            'requester_id' => ['required', 'string', 'size:26'],
            'required_date' => ['required', 'date'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => ['nullable', 'string', 'size:26'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['required', 'string', 'size:26'],
            'lines.*.estimated_unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string'],
        ];
    }
}
