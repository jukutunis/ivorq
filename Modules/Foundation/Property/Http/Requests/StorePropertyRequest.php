<?php

namespace Modules\Foundation\Property\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \Modules\Foundation\Property\Models\Property::class);
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'string', 'exists:companies,id'],
            'name'       => ['required', 'string', 'max:255'],
            'code'       => ['required', 'string', 'max:20', 'unique:properties,code'],
            'email'      => ['nullable', 'email'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'address'    => ['nullable', 'string'],
            'city'       => ['nullable', 'string', 'max:100'],
            'country'    => ['nullable', 'string', 'max:100'],
            'timezone'   => ['nullable', 'string', 'max:100'],
            'currency'   => ['nullable', 'string', 'size:3'],
        ];
    }
}
