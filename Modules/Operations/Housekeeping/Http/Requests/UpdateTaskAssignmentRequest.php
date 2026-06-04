<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Shared\Services\CurrentPropertyService;

class UpdateTaskAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = TaskAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'user_id'       => ['sometimes', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'department_id' => ['sometimes', 'string', Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
