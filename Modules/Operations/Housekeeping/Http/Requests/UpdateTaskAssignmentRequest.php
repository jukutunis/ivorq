<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Housekeeping\Models\TaskAssignment;

class UpdateTaskAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = TaskAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        return [
            'user_id'       => ['sometimes', 'string', 'exists:users,id'],
            'department_id' => ['sometimes', 'string', 'exists:departments,id'],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
