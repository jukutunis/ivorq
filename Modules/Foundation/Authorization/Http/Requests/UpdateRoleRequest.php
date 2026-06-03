<?php

namespace Modules\Foundation\Authorization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', \Spatie\Permission\Models\Role::class);
    }

    public function rules(): array
    {
        return [
            'permissions'   => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }
}
