<?php

namespace Modules\Foundation\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Shared\Services\CurrentPropertyService;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = \Modules\Foundation\User\Models\User::find($this->route('user'));

        return $user && $this->user()->can('update', $user);
    }

    public function rules(): array
    {
        $userId     = $this->route('user');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'name'          => ['sometimes', 'string', 'max:255'],
            'email'         => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'password'      => ['sometimes', 'string', 'min:8', 'confirmed'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'position_id'   => ['nullable', Rule::exists('positions', 'id')->where('property_id', $propertyId)],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }
}
