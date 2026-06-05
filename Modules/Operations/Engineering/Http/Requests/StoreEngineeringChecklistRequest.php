<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Models\EngineeringChecklist;

class StoreEngineeringChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EngineeringChecklist::class);
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'checklist_type' => ['required', Rule::enum(EngineeringChecklistTypeEnum::class)],
            'is_active'      => ['nullable', 'boolean'],
        ];
    }
}
