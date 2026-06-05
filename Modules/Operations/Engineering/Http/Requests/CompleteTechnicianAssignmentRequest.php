<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\TechnicianAssignment;

class CompleteTechnicianAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = TechnicianAssignment::find($this->route('assignment'));

        return $assignment && $this->user()->can('update', $assignment);
    }

    public function rules(): array
    {
        return [
            'hours_worked' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'remarks'      => ['nullable', 'string', 'max:1000'],
        ];
    }
}
