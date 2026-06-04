<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Shared\Services\CurrentPropertyService;

class UpdateCleaningChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = CleaningChecklist::find($this->route('checklist'));

        return $checklist && $this->user()->can('update', $checklist);
    }

    public function rules(): array
    {
        $checklistId = $this->route('checklist');
        $propertyId  = app(CurrentPropertyService::class)->getId();

        return [
            'name'        => ['sometimes', 'string', 'max:255',
                "unique:cleaning_checklists,name,{$checklistId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'task_type'   => ['nullable', Rule::enum(TaskTypeEnum::class)],
            'description' => ['nullable', 'string'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }
}
