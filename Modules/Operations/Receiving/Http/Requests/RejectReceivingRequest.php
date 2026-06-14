<?php

namespace Modules\Operations\Receiving\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('receiving.approve');
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => 'required|string|max:1000'
        ];
    }
}
