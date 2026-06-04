<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Shared\Services\CurrentPropertyService;

class StoreTaskAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TaskAssignment::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'user_id'       => ['required', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'department_id' => ['required', 'string', Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
