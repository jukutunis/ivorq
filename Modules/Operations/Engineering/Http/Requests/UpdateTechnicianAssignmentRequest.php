<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\TechnicianRoleEnum;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Shared\Services\CurrentPropertyService;

class UpdateTechnicianAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = TechnicianAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'user_id'       => ['sometimes', 'string',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'role'          => ['sometimes', Rule::enum(TechnicianRoleEnum::class)],
            'department_id' => ['nullable', 'string',
                Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'remarks'       => ['nullable', 'string', 'max:1000'],
            'status'        => ['prohibited'],
        ];
    }
}
