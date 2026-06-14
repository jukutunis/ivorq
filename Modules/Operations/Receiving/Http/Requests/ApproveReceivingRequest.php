<?php

namespace Modules\Operations\Receiving\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('receiving.approve');
    }

    public function rules(): array
    {
        return [
            'approval_notes' => 'nullable|string'
        ];
    }
}
