<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Shared\Services\CurrentPropertyService;

class StoreZoneAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ZoneAssignment::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'user_id'       => ['required', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'department_id' => ['required', 'string', Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
