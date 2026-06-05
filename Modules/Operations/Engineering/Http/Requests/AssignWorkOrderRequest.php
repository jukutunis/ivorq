<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\TechnicianRoleEnum;
use Modules\Operations\Engineering\Models\WorkOrder;
use Shared\Services\CurrentPropertyService;

class AssignWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workOrder = WorkOrder::find($this->route('wo'));

        return $workOrder && $this->user()->can('assign', $workOrder);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'user_id'       => ['required', 'string',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'role'          => ['required', Rule::enum(TechnicianRoleEnum::class)],
            'department_id' => ['nullable', 'string',
                Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
        ];
    }
}
