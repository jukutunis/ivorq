<?php

namespace App\Http\Requests\Ivorq;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDraftShiftLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'string', 'in:low,normal,high'],
            'requires_follow_up' => ['boolean'],
            'shift_id' => ['nullable', 'string', 'size:26'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'area' => ['nullable', 'string', 'max:255'],
        ];
    }
}
