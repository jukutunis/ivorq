<?php

namespace Modules\Foundation\Property\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $property = \Modules\Foundation\Property\Models\Property::find($this->route('property'));

        return $property && $this->user()->can('update', $property);
    }

    public function rules(): array
    {
        $propertyId = $this->route('property');

        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'code'     => ['sometimes', 'string', 'max:20', Rule::unique('properties', 'code')->ignore($propertyId)->whereNull('deleted_at')],
            'email'    => ['nullable', 'email'],
            'phone'    => ['nullable', 'string', 'max:50'],
            'address'  => ['nullable', 'string'],
            'city'     => ['nullable', 'string', 'max:100'],
            'country'  => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
