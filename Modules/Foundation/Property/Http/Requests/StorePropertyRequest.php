<?php

namespace Modules\Foundation\Property\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \Modules\Foundation\Property\Models\Property::class);
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'string', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'name'       => ['required', 'string', 'max:255'],
            'code'       => ['required', 'string', 'max:20', Rule::unique('properties', 'code')->whereNull('deleted_at')],
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
