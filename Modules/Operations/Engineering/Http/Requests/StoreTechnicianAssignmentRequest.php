<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\TechnicianRoleEnum;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Shared\Services\CurrentPropertyService;

class StoreTechnicianAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TechnicianAssignment::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'work_order_id' => ['required', 'string',
                Rule::exists('work_orders', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'user_id'       => ['required', 'string',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'role'          => ['required', Rule::enum(TechnicianRoleEnum::class)],
            'department_id' => ['nullable', 'string',
                Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'status'        => ['prohibited'],
        ];
    }
}
