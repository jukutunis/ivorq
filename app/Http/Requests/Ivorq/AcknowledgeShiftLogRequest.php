<?php

namespace App\Http\Requests\Ivorq;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeShiftLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
