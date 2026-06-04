<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Shared\Services\CurrentPropertyService;

class ReassignZoneAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = ZoneAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'user_id'       => ['required', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'department_id' => ['required', 'string', Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'start_date'    => ['required', 'date'],
        ];
    }
}
