<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

class ReassignInspectionClaimRequest extends ConfirmInspectionClaimReassignmentRequest
{
    public function rules(): array
    {
        return [
            'replacement_inspector_id' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,159}\z/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = ['replacement_inspector_id', 'reason', 'idempotency_key', '_token', '_method'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add('request', "The {$field} authority parameter is not accepted.");
            }
        });
    }
}
