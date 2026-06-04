<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Zoning\Models\ZoneAssignment;

class StoreZoneAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ZoneAssignment::class);
    }

    public function rules(): array
    {
        return [
            'user_id'       => ['required', 'string', 'exists:users,id'],
            'department_id' => ['required', 'string', 'exists:departments,id'],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
