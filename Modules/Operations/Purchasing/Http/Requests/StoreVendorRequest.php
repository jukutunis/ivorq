<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_category_id' => ['required', 'string', 'size:26'],
            'vendor_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'default_currency_code' => ['nullable', 'string', 'max:10'],
            'is_active' => ['boolean'],
            
            'contacts' => ['nullable', 'array'],
            'contacts.*.contact_name' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.position' => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary' => ['boolean'],
        ];
    }
}
