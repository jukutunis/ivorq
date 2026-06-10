<?php

namespace Modules\Operations\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by controller/policy
    }

    public function rules(): array
    {
        return [
            'category_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
