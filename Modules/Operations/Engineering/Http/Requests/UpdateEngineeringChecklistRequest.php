<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Models\EngineeringChecklist;

class UpdateEngineeringChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = EngineeringChecklist::find($this->route('checklist'));

        return $checklist && $this->user()->can('update', $checklist);
    }

    public function rules(): array
    {
        return [
            'title'          => ['sometimes', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'checklist_type' => ['sometimes', Rule::enum(EngineeringChecklistTypeEnum::class)],
            'is_active'      => ['nullable', 'boolean'],
        ];
    }
}
