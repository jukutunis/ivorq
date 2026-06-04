<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;

class ChangeCleaningTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = CleaningTask::find($this->route('task'));

        return $task && $this->user()->can('changeStatus', $task);
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::enum(TaskStatusEnum::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
