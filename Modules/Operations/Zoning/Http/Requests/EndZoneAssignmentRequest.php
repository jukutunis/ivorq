<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Zoning\Models\ZoneAssignment;

class EndZoneAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = ZoneAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
