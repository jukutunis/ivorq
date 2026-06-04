<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Shared\Services\CurrentPropertyService;

class StoreCleaningChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CleaningChecklist::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'name'        => ['required', 'string', 'max:255',
                "unique:cleaning_checklists,name,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'task_type'   => ['nullable', Rule::enum(TaskTypeEnum::class)],
            'description' => ['nullable', 'string'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }
}
