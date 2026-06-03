<?php

namespace Modules\Foundation\Property\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \Modules\Foundation\Property\Models\Company::class);
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['nullable', 'email'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
        ];
    }
}
