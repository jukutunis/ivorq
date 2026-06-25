<?php

namespace App\Http\Requests\Ivorq;

use Illuminate\Foundation\Http\FormRequest;

class StoreLogbookEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'string', 'in:low,normal,high'],
            'requires_follow_up' => ['boolean'],
            'department_id' => ['required', 'string', 'size:26'],
        ];
    }
}
