<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'string', 'exists:vendors,id'],
            'purchase_request_id' => ['required', 'string', 'exists:purchase_requests,id'],
            'expected_delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
