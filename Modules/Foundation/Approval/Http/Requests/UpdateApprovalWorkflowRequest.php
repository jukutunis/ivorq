<?php

namespace Modules\Foundation\Approval\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApprovalWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // handled by policy
    }

    public function rules(): array
    {
        return [
            'workflow_name' => ['sometimes', 'string', 'max:100'],
            'module' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.sequence_no' => ['required_with:steps', 'integer', 'min:1'],
            'steps.*.role_name' => ['nullable', 'string', 'max:100'],
            'steps.*.permission_name' => ['nullable', 'string', 'max:100'],
            'steps.*.approval_limit' => ['nullable', 'numeric', 'min:0'],
            'steps.*.currency_code' => ['nullable', 'string', 'max:10'],
            'steps.*.is_required' => ['boolean'],
        ];
    }
}
