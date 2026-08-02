<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

class ConfirmInspectionPassRequest extends PassInspectionRequest
{
    public function rules(): array
    {
        return [
            'release_reason' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->all()), ['release_reason', 'password', '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This lifecycle authority parameter is not accepted.');
            }
        });
    }
}
