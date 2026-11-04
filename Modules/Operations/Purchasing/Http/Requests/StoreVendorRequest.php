<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Shared\Services\CurrentPropertyService;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'company_id' => ['prohibited'],
            'property_id' => ['prohibited'],
            'is_approved' => ['prohibited'],
            'vendor_category_id' => [
                'required',
                'string',
                'size:26',
                Rule::exists('vendor_categories', 'id')->where(
                    fn ($query) => $query->where('property_id', $propertyId)->whereNull('deleted_at')
                ),
            ],
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
