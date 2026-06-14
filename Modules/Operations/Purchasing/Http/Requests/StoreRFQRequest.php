<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRFQRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchasing.create');
    }

    public function rules(): array
    {
        return [
            'purchase_request_id' => ['required', 'string', 'exists:purchase_requests,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'deadline_at' => ['required', 'date', 'after:today'],
            'vendor_ids' => ['required', 'array', 'min:1'],
            'vendor_ids.*' => ['required', 'string', 'exists:vendors,id'],
        ];
    }
}
