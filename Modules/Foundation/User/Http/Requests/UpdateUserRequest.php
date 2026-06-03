<?php

namespace Modules\Foundation\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = \Modules\Foundation\User\Models\User::find($this->route('user'));

        return $user && $this->user()->can('update', $user);
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name'          => ['sometimes', 'string', 'max:255'],
            'email'         => ['sometimes', 'email', "unique:users,email,{$userId}"],
            'password'      => ['sometimes', 'string', 'min:8', 'confirmed'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id'   => ['nullable', 'exists:positions,id'],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }
}
