<?php

namespace App\Http\Requests\Ivorq;

use Illuminate\Foundation\Http\FormRequest;

class StoreLogbookEntrySelfCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'correction_reason' => ['required', 'string'],
            'correction_content' => ['required', 'string'],
        ];
    }
}
