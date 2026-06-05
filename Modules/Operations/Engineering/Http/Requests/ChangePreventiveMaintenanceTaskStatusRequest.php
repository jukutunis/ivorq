<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;

class ChangePreventiveMaintenanceTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = PreventiveMaintenanceTask::find($this->route('task'));

        return $task && $this->user()->can('changeStatus', $task);
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::enum(PmTaskStatusEnum::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
