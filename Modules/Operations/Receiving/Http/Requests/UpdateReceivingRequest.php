<?php

namespace Modules\Operations\Receiving\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('receiving');
        return $this->user()->can('update', $document);
    }

    public function rules(): array
    {
        return [
            'vendor_delivery_no' => 'nullable|string|max:255',
            'received_at' => 'nullable|date',
            'remarks' => 'nullable|string',
        ];
    }
}
