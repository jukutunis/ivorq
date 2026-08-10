<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTaskAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'ulid'],
            'department_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
            'expected_active_assignment_id' => ['present', 'nullable', 'ulid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), [
                'user_id',
                'department_id',
                'idempotency_key',
                'expected_active_assignment_id',
            ]);
            if ($unknown !== []) {
                $validator->errors()->add('request', 'HOUSEKEEPING_ASSIGNMENT_AUTHORITY_FIELDS_REJECTED');
            }
        });
    }
}
