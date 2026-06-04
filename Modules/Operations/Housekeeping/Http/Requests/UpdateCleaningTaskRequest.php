<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Shared\Services\CurrentPropertyService;

class UpdateCleaningTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = CleaningTask::find($this->route('task'));

        return $task && $this->user()->can('update', $task);
    }

    public function rules(): array
    {
        $taskId     = $this->route('task');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'task_code'                  => ['sometimes', 'string', 'max:20',
                "unique:cleaning_tasks,task_code,{$taskId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'title'                      => ['sometimes', 'string', 'max:255'],
            'description'                => ['nullable', 'string'],
            'task_type'                  => ['sometimes', Rule::enum(TaskTypeEnum::class)],
            'priority'                   => ['nullable', 'integer', 'min:1', 'max:5'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'room_id'                    => ['nullable', 'string', 'exists:rooms,id'],
            'zone_id'                    => ['nullable', 'string', 'exists:zones,id'],
            'due_date'                   => ['nullable', 'date'],
            'status'                     => ['prohibited'],
            'completed_by'               => ['prohibited'],
            'completed_at'               => ['prohibited'],
            'started_at'                 => ['prohibited'],
        ];
    }
}
