<?php

namespace Modules\Foundation\Department\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $position = \Modules\Foundation\Department\Models\Position::with('department')
            ->find($this->route('position'));

        return $position && $this->user()->can('update', $position->department);
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'code'        => ['sometimes', 'string', 'max:20'],
            'level'       => ['sometimes', 'integer', 'min:1', 'max:10'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
