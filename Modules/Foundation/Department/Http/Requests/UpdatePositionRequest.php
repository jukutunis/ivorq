<?php

namespace Modules\Foundation\Department\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $position = \Modules\Foundation\Department\Models\Position::find($this->route('position'));

        return $position && $this->user()->can('update', $position);
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'code'        => ['sometimes', 'string', 'max:20', \Illuminate\Validation\Rule::unique('positions', 'code')->ignore($this->route('position'))],
            'level'       => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
