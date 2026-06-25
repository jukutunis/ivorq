<?php

namespace App\Http\Requests\Ivorq;

use Illuminate\Foundation\Http\FormRequest;

class StoreLogbookEntryFollowUpResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string'],
        ];
    }
}
