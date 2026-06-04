<?php

namespace Modules\Foundation\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Shared\Services\CurrentPropertyService;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \Modules\Foundation\User\Models\User::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'position_id'   => ['nullable', Rule::exists('positions', 'id')->where('property_id', $propertyId)],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }
}
