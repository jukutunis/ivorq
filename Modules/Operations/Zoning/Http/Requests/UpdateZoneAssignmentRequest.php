<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Zoning\Models\ZoneAssignment;

class UpdateZoneAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = ZoneAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        return [
            'user_id'       => ['sometimes', 'string', 'exists:users,id'],
            'department_id' => ['sometimes', 'string', 'exists:departments,id'],
            'start_date'    => ['sometimes', 'date'],
            'end_date'      => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
