<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Zoning\Models\ZoneAssignment;

class ReassignZoneAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = ZoneAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        return [
            'user_id'       => ['required', 'string', 'exists:users,id'],
            'department_id' => ['required', 'string', 'exists:departments,id'],
            'start_date'    => ['required', 'date'],
        ];
    }
}
