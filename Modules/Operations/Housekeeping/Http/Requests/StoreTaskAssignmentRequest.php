<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Housekeeping\Models\TaskAssignment;

class StoreTaskAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TaskAssignment::class);
    }

    public function rules(): array
    {
        return [
            'user_id'       => ['required', 'string', 'exists:users,id'],
            'department_id' => ['required', 'string', 'exists:departments,id'],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
