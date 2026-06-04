<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Shared\Services\CurrentPropertyService;

class StoreCleaningTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CleaningTask::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'task_code'                  => ['required', 'string', 'max:20',
                "unique:cleaning_tasks,task_code,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'title'                      => ['required', 'string', 'max:255'],
            'description'                => ['nullable', 'string'],
            'task_type'                  => ['required', Rule::enum(TaskTypeEnum::class)],
            'priority'                   => ['nullable', 'integer', 'min:1', 'max:5'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'room_id'                    => ['nullable', 'string', 'exists:rooms,id'],
            'zone_id'                    => ['nullable', 'string', 'exists:zones,id'],
            'due_date'                   => ['nullable', 'date'],
            'status'                     => ['prohibited'],
            'completed_by'               => ['prohibited'],
        ];
    }
}
